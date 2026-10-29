<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Closure;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Events\Reordered;
use Support\Database\Eloquent\Ordering\Exceptions\InvalidAnchor;
use Support\Database\Eloquent\Ordering\Exceptions\MembershipMismatch;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;

final class OrderedRelation
{
    public const string RANK_KEY = 'rank_key';

    public const int MAX_KEY_LENGTH = 64;

    private const string KEY_NAME = 'id';

    /** @var array<class-string, array<class-string, list<object>>> */
    private static array $attributes = [];

    /** @var array<string, bool> */
    private static array $declaredRelations = [];

    /** @var array<string, true> */
    private static array $validatedRelations = [];

    private readonly string $relationName;

    private readonly RelationKeys $keys;

    public function __construct(
        public readonly Model&OrdersRelations&Revisioned $parent,
        public readonly NamesConcurrencyRevision $revision,
    ) {
        $declaration = self::declarations($parent)[(string) $revision->value] ?? throw new LogicException(sprintf(
            '[%s] declares no ordered relation for the [%s] revision.',
            $parent::class,
            $revision->value,
        ));

        $this->relationName = $declaration['declaration']->relation;
        $this->keys = self::describe($declaration['relation']);
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<Model>  $held
     */
    public static function of(Model&Ordered $item, array $attributes, array $held = []): null|self
    {
        $membership = self::membershipNamedBy($item, $attributes);

        if ($membership === null) {
            return null;
        }

        ['parent' => $parent, 'revision' => $revision, 'keys' => $keys] = $membership;

        foreach ([...$held, ...$item instanceof Pivot ? [$item->pivotParent] : []] as $model) {
            $candidate = self::asParent($model);

            if ($candidate !== null && self::isNamed($membership, $attributes, $candidate)) {
                self::requireConnectionOf($item, $candidate);

                return new self($candidate, $revision);
            }
        }

        $loaded = self::asParent($parent->newQueryWithoutScopes()->where($keys->ownerKey, $attributes[$keys->foreignKey])->first()) ?? throw new LogicException(sprintf(
            '[%s:%s] names a [%s] that does not exist.',
            $item::class,
            $item->getKey(),
            $parent::class,
        ));

        return new self($loaded, $revision);
    }

    /**
     * @internal
     */
    public static function in(Model $parent, Model&Ordered $item): self
    {
        throw_unless($parent->exists, LogicException::class, sprintf('[%s] must be saved before it orders a member.', $parent::class));

        $membership = array_find(self::memberships($item), static fn (array $membership): bool => $parent::class === $membership['parent']::class);
        $candidate = self::asParent($parent);

        if ($membership === null || $candidate === null) {
            throw new LogicException(sprintf('[%s] is not ordered in [%s].', $item::class, $parent::class));
        }

        self::requireConnectionOf($item, $candidate);

        return new self($candidate, $membership['revision']);
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function names(Model&Ordered $item, array $attributes, Model $parent): bool
    {
        $membership = self::membershipNamedBy($item, $attributes);

        return $membership !== null && self::isNamed($membership, $attributes, $parent);
    }

    /**
     * @internal
     *
     * @param  list<null|self>  $relations
     * @return list<self>
     */
    public static function lockAll(array $relations): array
    {
        $unique = [];

        foreach ($relations as $relation) {
            if ($relation !== null) {
                $unique[spl_object_id($relation->parent)] = $relation;
            }
        }

        usort($unique, static fn (self $first, self $second): int => [$first->parent->getMorphClass(), $first->parent->getKey()]
            <=> [$second->parent->getMorphClass(), $second->parent->getKey()]);

        foreach ($unique as $relation) {
            $relation->lock();
        }

        return $unique;
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public static function membershipColumns(Model&Ordered $item): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(static fn (array $membership): array => $membership['keys']->columns(), self::memberships($item)),
        )));
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public static function watchedColumns(Model&Ordered $item): array
    {
        return [self::RANK_KEY, ...self::membershipColumns($item), OrderedRelations::DELETED_AT];
    }

    public function concurrencyRevision(): int
    {
        return intval($this->parentRow()->useWritePdo()->value($this->column()));
    }

    /**
     * @return HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>
     */
    public function query(): Relation
    {
        $relation = $this->scopedRelation();

        if ($relation instanceof BelongsToMany) {
            $relation->withPivot($this->keys->item->getKeyName());
        }

        return $relation
            ->orderBy($this->keys->item->qualifyColumn(self::RANK_KEY))
            ->orderBy($this->keys->item->getQualifiedKeyName());
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function reorder(array $ids, null|int $expected): OrderChange
    {
        return OrderWrite::run($this->parent, function () use ($ids, $expected): OrderChange {
            self::lockAll([$this]);
            $this->compareConcurrencyRevision($expected);

            $members = array_keys($this->rankKeys($this->members()));
            $order = $this->match($members, $ids);

            if ($order === $members) {
                return $this->change([]);
            }

            $keys = $this->rankKeys($this->indexedRows());
            $sequence = $this->sequence(array_keys($keys), $members, $order);

            $this->rewrite($keys, array_combine($sequence, FractionalIndex::keysBetween(null, null, count($sequence))));
            $this->refreshConcurrencyRevision();

            $before = array_flip($members);
            $moved = [];

            foreach ($order as $index => $id) {
                if ($before[$id] !== $index) {
                    $moved[$id] = $before[$id] + 1;
                }
            }

            return $this->announce($this->change($moved));
        });
    }

    /**
     * @internal
     */
    public function move(Model&Ordered $item, Placement $placement, self $source): OrderChange
    {
        $id = self::keyOf($item);
        $previous = $source->position($item) ?? throw new LogicException(sprintf('[%s:%s] has no place in its order.', $item::class, $id));

        if ($this->is($source)) {
            if ($this->holdsPlace($id, $placement)) {
                return $this->change([]);
            }

            $this->write($item, [self::RANK_KEY => $this->keyAt($placement, $id)]);
            $this->refreshWith($source);

            return $this->announce($this->change([$id => $previous]));
        }

        $this->write($item, [
            ...$source->keys->detached(),
            ...$this->keys->pointingAt($this->parent),
            self::RANK_KEY => $this->keyAt($placement),
        ]);
        $this->refreshWith($source);

        return $this->entered($item, $source, $previous);
    }

    /**
     * @internal
     */
    public function entered(Model&Ordered $item, self $source, int $previous): OrderChange
    {
        $id = self::keyOf($item);
        $left = $source->announce($source->change([$id => $previous]));

        return $this->announce($this->change([$id => null], $left));
    }

    /**
     * @internal
     */
    public function placed(Model&Ordered $item): void
    {
        $change = $this->change([self::keyOf($item) => null]);

        if ($change->changed[0]->position !== null) {
            $this->announce($change);
        }
    }

    /**
     * @internal
     */
    public function removed(Model&Ordered $item, int $previous): void
    {
        $this->announce($this->change([self::keyOf($item) => $previous]));
    }

    /**
     * @internal
     */
    public function is(self $other): bool
    {
        return $this->revision === $other->revision
            && $this->parent->getMorphClass() === $other->parent->getMorphClass()
            && self::sameKey($this->parent->getKey(), $other->parent->getKey());
    }

    /**
     * @internal
     */
    public function compareConcurrencyRevision(null|int $expected): void
    {
        if ($expected !== null && $this->parent->concurrencyRevision($this->revision) !== $expected) {
            throw new StaleConcurrencyRevision($this->parent, $this->revision, $expected);
        }
    }

    /**
     * @internal
     */
    public function refreshConcurrencyRevision(): void
    {
        $this->fillParent($this->parentRow()->useWritePdo()->value($this->column()));
    }

    /**
     * @internal
     */
    public function keyAt(Placement $placement, null|int|string $exclude = null): string
    {
        $key = FractionalIndex::keyBetween(...$this->bounds($placement, $exclude, $this->indexedRows(...)));

        if (strlen($key) <= self::MAX_KEY_LENGTH) {
            return $key;
        }

        $this->rebalance();

        return FractionalIndex::keyBetween(...$this->bounds($placement, $exclude, $this->indexedRows(...)));
    }

    /**
     * @internal
     */
    public function position(Model $item): null|int
    {
        $rankKey = $this->keys->item->qualifyColumn(self::RANK_KEY);
        $key = $this->members()->where($this->keys->item->getQualifiedKeyName(), $item->getKey())->value($rankKey);

        if (! is_string($key)) {
            return null;
        }

        return $this->members()->where($rankKey, '<', $key)->count() + 1;
    }

    /**
     * @return list<array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys}>
     */
    private static function memberships(Model&Ordered $item): array
    {
        $memberships = [];

        foreach (self::attributes($item, OrderedIn::class) as $orderedIn) {
            $class = $orderedIn->parent;
            $parent = self::asParent(new $class) ?? throw new LogicException(sprintf(
                '[%s] is ordered in [%s], which does not order relations.',
                $item::class,
                $class,
            ));
            $parent->setConnection($item->getConnectionName());

            $declarations = array_values(array_filter(
                self::declarations($parent),
                static fn (array $declaration): bool => self::itemClass($declaration['relation']) === $item::class,
            ));

            $declaration = $declarations[0] ?? throw new LogicException(sprintf(
                '[%s] declares no ordered relation of [%s].',
                $parent::class,
                $item::class,
            ));

            $memberships[] = [
                'parent' => $parent,
                'revision' => $declaration['declaration']->revision,
                'keys' => self::describe($declaration['relation']),
            ];
        }

        return $memberships;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return null|array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys}
     */
    private static function membershipNamedBy(Model&Ordered $item, array $attributes): null|array
    {
        $memberships = array_values(array_filter(
            self::memberships($item),
            static fn (array $membership): bool => $membership['keys']->names($attributes),
        ));

        throw_if(count($memberships) > 1, LogicException::class, sprintf(
            '[%s:%s] belongs to more than one parent that orders it.',
            $item::class,
            $item->getKey(),
        ));

        return $memberships[0] ?? null;
    }

    /**
     * @param  array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys}  $membership
     * @param  array<string, mixed>  $attributes
     */
    private static function isNamed(array $membership, array $attributes, Model $parent): bool
    {
        return $parent::class === $membership['parent']::class
            && self::sameKey($parent->getAttribute($membership['keys']->ownerKey), $attributes[$membership['keys']->foreignKey]);
    }

    private static function requireConnectionOf(Model $item, Model $parent): void
    {
        $connection = $item->getConnection()->getName();

        throw_unless($parent->getConnection()->getName() === $connection, LogicException::class, sprintf(
            '[%s:%s] on the [%s] connection cannot be ordered in [%s:%s] on the [%s] connection.',
            $item::class,
            $item->getKey(),
            $connection,
            $parent::class,
            $parent->getKey(),
            $parent->getConnection()->getName(),
        ));
    }

    /**
     * @return array<string, array{declaration: OrdersRelation, relation: HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>}>
     */
    private static function declarations(Model $parent): array
    {
        $declarations = [];
        $relations = [];

        foreach (self::attributes($parent, OrdersRelation::class) as $declaration) {
            $relation = self::relation($parent, $declaration->relation);
            $item = self::itemClass($relation);

            throw_if(array_key_exists($declaration->name(), $declarations), LogicException::class, sprintf(
                '[%s] orders more than one relation by the [%s] revision.',
                $parent::class,
                $declaration->name(),
            ));

            throw_if(array_key_exists($item, $relations), LogicException::class, sprintf(
                '[%s] orders [%s] in more than one relation: [%s] and [%s].',
                $parent::class,
                $item,
                $relations[$item] ?? '',
                $declaration->relation,
            ));

            $declarations[$declaration->name()] = ['declaration' => $declaration, 'relation' => $relation];
            $relations[$item] = $declaration->relation;
        }

        return $declarations;
    }

    /**
     * @template TAttribute of object
     *
     * @param  class-string<TAttribute>  $attribute
     * @return list<TAttribute>
     */
    private static function attributes(Model $model, string $attribute): array
    {
        if (! array_key_exists($attribute, self::$attributes[$model::class] ?? [])) {
            $instances = [];

            for ($class = new ReflectionClass($model); $class !== false; $class = $class->getParentClass()) {
                foreach ($class->getAttributes($attribute) as $reflection) {
                    $instances[] = $reflection->newInstance();
                }
            }

            self::$attributes[$model::class][$attribute] = $instances;
        }

        /** @var list<TAttribute> */
        return self::$attributes[$model::class][$attribute];
    }

    /**
     * @return HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>
     */
    private static function relation(Model $parent, string $name): HasMany|MorphMany|BelongsToMany
    {
        $message = sprintf('[%s::%s()] must return a HasMany, MorphMany, BelongsToMany, or MorphToMany relation to an Ordered model.', $parent::class, $name);

        throw_unless(self::$declaredRelations[$parent::class.'::'.$name] ??= self::declaresOrderableRelation($parent, $name), LogicException::class, $message);

        $relation = $parent->{$name}();

        throw_unless($relation instanceof HasMany || $relation instanceof MorphMany || $relation instanceof BelongsToMany, LogicException::class, $message);

        throw_if($relation instanceof MorphToMany && $relation->getInverse(), LogicException::class, sprintf(
            '[%s::%s()] is the inverse side of a MorphToMany relation. Order it from the side that names the morph type.',
            $parent::class,
            $name,
        ));

        throw_if($relation instanceof BelongsToMany && ! is_a($relation->getPivotClass(), Ordered::class, true), LogicException::class, sprintf(
            '[%s::%s()] must name an Ordered pivot class through using().',
            $parent::class,
            $name,
        ));

        throw_unless(is_a(self::itemClass($relation), Ordered::class, true), LogicException::class, $message);

        $keys = self::describe($relation);
        $validated = implode(':', [
            $parent::class.'::'.$name,
            self::itemClass($relation),
            $parent->getKeyName(),
            $keys->ownerKey,
            $keys->item->getKeyName(),
            method_exists($keys->item, 'getDeletedAtColumn') ? $keys->item->getDeletedAtColumn() : '',
        ]);

        if (! array_key_exists($validated, self::$validatedRelations)) {
            self::requireIdKeys($parent, $name, $relation);
            self::requireSoftDeleteColumn($parent, $name, $relation);

            self::$validatedRelations[$validated] = true;
        }

        return $relation;
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     */
    private static function requireSoftDeleteColumn(Model $parent, string $name, HasMany|MorphMany|BelongsToMany $relation): void
    {
        $item = self::describe($relation)->item;

        if (! in_array(SoftDeletes::class, class_uses_recursive($item), true) || ! method_exists($item, 'getDeletedAtColumn')) {
            return;
        }

        $column = $item->getDeletedAtColumn();

        throw_unless($column === OrderedRelations::DELETED_AT, LogicException::class, sprintf(
            '[%s::%s()] orders [%s], which soft deletes through [%s]. Ordered relations require the soft delete column [%s], because the order trigger and the rank key queries name it.',
            $parent::class,
            $name,
            $item::class,
            $column,
            OrderedRelations::DELETED_AT,
        ));
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     */
    private static function requireIdKeys(Model $parent, string $name, HasMany|MorphMany|BelongsToMany $relation): void
    {
        $item = self::describe($relation)->item;

        foreach ([
            'the primary key of the parent' => $parent->getKeyName(),
            'the owner key of the relation' => $relation instanceof BelongsToMany ? $relation->getParentKeyName() : $relation->getLocalKeyName(),
            'the primary key of the item' => $item->getKeyName(),
        ] as $role => $key) {
            throw_unless($key === self::KEY_NAME, LogicException::class, sprintf(
                '[%s::%s()] uses [%s] as %s. Ordered relations require the key [%s], because the named revision trigger matches the parent by it.',
                $parent::class,
                $name,
                $key,
                $role,
                self::KEY_NAME,
            ));
        }
    }

    private static function declaresOrderableRelation(Model $parent, string $name): bool
    {
        if (! method_exists($parent, $name)) {
            return false;
        }

        $method = new ReflectionMethod($parent, $name);
        $type = $method->getReturnType();

        return $method->isPublic()
            && ! $method->isStatic()
            && $type instanceof ReflectionNamedType
            && array_any(
                [HasMany::class, MorphMany::class, BelongsToMany::class],
                static fn (string $class): bool => is_a($type->getName(), $class, true),
            );
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     * @return class-string
     */
    private static function itemClass(HasMany|MorphMany|BelongsToMany $relation): string
    {
        return $relation instanceof BelongsToMany ? $relation->getPivotClass() : $relation->getRelated()::class;
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     */
    private static function describe(HasMany|MorphMany|BelongsToMany $relation): RelationKeys
    {
        $morph = $relation instanceof MorphMany || $relation instanceof MorphToMany
            ? [$relation->getMorphType(), $relation->getMorphClass()]
            : [];

        if ($relation instanceof BelongsToMany) {
            $class = $relation->getPivotClass();

            return new RelationKeys(
                (new $class)->setTable($relation->getTable())->setConnection($relation->getParent()->getConnectionName()),
                $relation->getForeignPivotKeyName(),
                $relation->getParentKeyName(),
                ...$morph,
            );
        }

        return new RelationKeys($relation->getRelated(), $relation->getForeignKeyName(), $relation->getLocalKeyName(), ...$morph);
    }

    private static function asParent(mixed $model): null|(Model&OrdersRelations&Revisioned)
    {
        return $model instanceof Model && $model instanceof OrdersRelations && $model instanceof Revisioned ? $model : null;
    }

    private static function sameKey(mixed $first, mixed $second): bool
    {
        return (is_int($first) || is_string($first))
            && (is_int($second) || is_string($second))
            && (string) $first === (string) $second;
    }

    private static function keyOrNull(mixed $key): null|string
    {
        return is_string($key) ? $key : null;
    }

    private static function keyOf(Model $item): int|string
    {
        $key = $item->getKey();

        throw_unless(is_int($key) || is_string($key), LogicException::class, sprintf('[%s] has no key.', $item::class));

        return $key;
    }

    /**
     * @param  Closure(): Builder  $rows
     * @return array{null|string, null|string}
     */
    private function bounds(Placement $placement, null|int|string $exclude, Closure $rows): array
    {
        $rankKey = $this->keys->item->qualifyColumn(self::RANK_KEY);
        $others = fn (Builder $query): Builder => $query->when(
            $exclude !== null,
            fn (Builder $query): Builder => $query->where($this->keys->item->getQualifiedKeyName(), '!=', $exclude),
        );

        if ($placement->first) {
            return [null, self::keyOrNull($others($rows())->min($rankKey))];
        }

        if ($placement->before !== null) {
            $anchor = $this->anchor($others($this->members()), $placement->before);

            return [self::keyOrNull($others($rows())->where($rankKey, '<', $anchor)->max($rankKey)), $anchor];
        }

        if ($placement->after !== null) {
            $anchor = $this->anchor($others($this->members()), $placement->after);

            return [$anchor, self::keyOrNull($others($rows())->where($rankKey, '>', $anchor)->min($rankKey))];
        }

        return [self::keyOrNull($others($rows())->max($rankKey)), null];
    }

    private function anchor(Builder $members, int|string $anchor): string
    {
        $id = $this->memberKey($anchor);
        $key = $id === null ? null : $members->where($this->keys->item->getQualifiedKeyName(), $id)->value($this->keys->item->qualifyColumn(self::RANK_KEY));

        throw_unless(is_string($key), new InvalidAnchor($this, $anchor));

        return $key;
    }

    private function holdsPlace(int|string $id, Placement $placement): bool
    {
        $key = $this->rows([$id])->value($this->keys->item->qualifyColumn(self::RANK_KEY));
        [$lower, $upper] = $this->bounds($placement, $id, $this->members(...));

        return is_string($key)
            && ($lower === null || strcmp($key, $lower) > 0)
            && ($upper === null || strcmp($key, $upper) < 0);
    }

    private function rebalance(): void
    {
        $keys = $this->rankKeys($this->indexedRows());

        OrderWrite::rebalanced($this, $this->rewrite($keys, array_combine(array_keys($keys), FractionalIndex::keysBetween(null, null, count($keys)))));
    }

    /**
     * @param  array<int|string, string>  $current
     * @param  array<int|string, string>  $next
     * @return list<int|string>
     */
    private function rewrite(array $current, array $next): array
    {
        $changed = array_filter($next, static fn (string $key, int|string $id): bool => ($current[$id] ?? null) !== $key, ARRAY_FILTER_USE_BOTH);

        if ($changed === []) {
            return [];
        }

        OrderWrite::update($this->keys->item, array_keys($changed), [self::RANK_KEY => null]);

        foreach ($changed as $id => $key) {
            OrderWrite::update($this->keys->item, [$id], [self::RANK_KEY => $key]);
        }

        return array_keys($changed);
    }

    /**
     * @param  list<int|string>  $members
     * @param  list<int|string>  $ids
     * @return list<int|string>
     */
    private function match(array $members, array $ids): array
    {
        $byKey = [];

        foreach ($members as $member) {
            $key = $this->memberKey($member);

            if ($key !== null) {
                $byKey[$key] = $member;
            }
        }

        $order = [];

        foreach ($ids as $id) {
            $key = $this->memberKey($id);

            throw_unless($key !== null && array_key_exists($key, $byKey) && ! array_key_exists($key, $order), new MembershipMismatch($this));

            $order[$key] = $byKey[$key];
        }

        throw_unless(count($order) === count($members), new MembershipMismatch($this));

        return array_values($order);
    }

    /**
     * @param  list<int|string>  $rows
     * @param  list<int|string>  $members
     * @param  list<int|string>  $order
     * @return list<int|string>
     */
    private function sequence(array $rows, array $members, array $order): array
    {
        $visible = array_flip($members);
        $sequence = [];
        $following = [];
        $previous = null;

        foreach ($rows as $id) {
            if (array_key_exists($id, $visible)) {
                $previous = $id;
            } elseif ($previous === null) {
                $sequence[] = $id;
            } else {
                $following[$previous][] = $id;
            }
        }

        foreach ($order as $id) {
            array_push($sequence, $id, ...($following[$id] ?? []));
        }

        return $sequence;
    }

    private function announce(OrderChange $change): OrderChange
    {
        OrderWrite::announcing($change);
        event(new Reordered($change));

        return $change;
    }

    /**
     * @param  array<int|string, null|int>  $moved
     */
    private function change(array $moved, null|OrderChange $source = null): OrderChange
    {
        $ids = array_keys($this->rankKeys($this->members()));
        $positions = array_flip($ids);

        return new OrderChange(
            relation: $this,
            order: $ids,
            changed: array_map(
                static fn (int|string $id, null|int $previous): PositionChange => new PositionChange(
                    $id,
                    array_key_exists($id, $positions) ? $positions[$id] + 1 : null,
                    $previous,
                ),
                array_keys($moved),
                array_values($moved),
            ),
            revision: $this->parent->concurrencyRevision($this->revision),
            source: $source,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(Model $item, array $attributes): void
    {
        $written = OrderWrite::update($item, [$item->getKey()], $attributes);
        $item->forceFill($written)->syncOriginalAttributes(array_keys($written));

        if ($item instanceof Revisioned) {
            $item->refreshConcurrencyRevisions();
        }
    }

    private function refreshWith(self $source): void
    {
        if ($source->parent !== $this->parent) {
            $source->refreshConcurrencyRevision();
        }

        $this->refreshConcurrencyRevision();
    }

    /**
     * @return array<int|string, string>
     */
    private function rankKeys(Builder $rows): array
    {
        $rankKey = $this->keys->item->qualifyColumn(self::RANK_KEY);
        $key = $this->keys->item->getQualifiedKeyName();

        /** @var array<int|string, string> */
        return $rows->whereNotNull($rankKey)->orderBy($rankKey)->orderBy($key)->pluck($rankKey, $key)->all();
    }

    private function memberKey(int|string $id): null|string
    {
        $key = (string) $id;
        $integer = in_array($this->keys->item->getKeyType(), ['int', 'integer'], true);

        if (Str::isUuid($key)) {
            return $integer && $this->keys->item->getIncrementing() ? null : strtolower($key);
        }

        if ($integer) {
            return (string) (int) $key === $key ? $key : null;
        }

        return in_array(HasUuids::class, class_uses_recursive($this->keys->item), true) ? null : $key;
    }

    /**
     * @return HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>
     */
    private function scopedRelation(): HasMany|MorphMany|BelongsToMany
    {
        $relation = self::relation($this->parent, $this->relationName);
        $relation->getBaseQuery()->reorder();
        $scopes = array_keys($relation->getRelated()->getGlobalScopes());

        return $relation->withoutGlobalScopes(array_values(array_diff($scopes, [SoftDeletingScope::class])));
    }

    private function members(): Builder
    {
        return $this->scopedRelation()->toBase();
    }

    private function indexedRows(): Builder
    {
        $rows = $this->keys->item->newQueryWithoutScopes()->toBase();

        foreach ($this->keys->pointingAt($this->parent) as $column => $value) {
            $rows->where($this->keys->item->qualifyColumn($column), $value);
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($this->keys->item), true)) {
            $rows->whereNull($this->keys->item->qualifyColumn(OrderedRelations::DELETED_AT));
        }

        return $rows;
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function rows(array $ids): Builder
    {
        return $this->members()->whereIn($this->keys->item->getQualifiedKeyName(), $ids);
    }

    private function lock(): void
    {
        /** @var array<string, mixed> $row */
        $row = (array) ($this->parentRow()->lock('for no key update')->first() ?? []);

        OrderWrite::locked($this, $row);
        $this->fillParent($row[$this->column()] ?? null);
    }

    private function parentRow(): Builder
    {
        return $this->parent->newQueryWithoutScopes()->whereKey($this->parent->getKey())->toBase();
    }

    private function column(): string
    {
        return $this->parent->concurrencyRevisionColumn($this->revision);
    }

    private function fillParent(mixed $revision): void
    {
        $column = $this->column();

        $this->parent->forceFill([$column => intval($revision)])->syncOriginalAttributes([$column]);
    }
}
