<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Support\Database\Eloquent\Concurrency\ConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Exceptions\InvalidAnchor;
use Support\Database\Eloquent\Ordering\Exceptions\MembershipMismatch;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;

final class OrderedRelation
{
    public const string RANK_KEY = 'rank_key';

    public const int MAX_KEY_LENGTH = 64;

    private readonly string $relationName;

    private readonly Model $related;

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
        $this->related = $declaration['relation']->getRelated();
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<Model>  $held
     */
    public static function of(Model&Ordered $item, array $attributes, array $held = []): null|self
    {
        $memberships = array_values(array_filter(
            self::memberships($item),
            static fn (array $membership): bool => ($attributes[$membership['foreignKey']] ?? null) !== null,
        ));

        if ($memberships === []) {
            return null;
        }

        throw_if(count($memberships) > 1, LogicException::class, sprintf(
            '[%s:%s] belongs to more than one parent that orders it.',
            $item::class,
            $item->getKey(),
        ));

        ['parent' => $parent, 'revision' => $revision, 'foreignKey' => $foreignKey, 'ownerKey' => $ownerKey] = $memberships[0];
        $key = $attributes[$foreignKey];

        foreach ($held as $model) {
            $candidate = self::asParent($model);

            if ($candidate !== null && $candidate::class === $parent::class && self::sameKey($candidate->getAttribute($ownerKey), $key)) {
                return new self($candidate, $revision);
            }
        }

        $loaded = self::asParent($parent->newQueryWithoutScopes()->where($ownerKey, $key)->first()) ?? throw new LogicException(sprintf(
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

        return new self($candidate, $membership['revision']);
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
    public static function foreignKeys(Model&Ordered $item): array
    {
        return array_column(self::memberships($item), 'foreignKey');
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public static function watchedColumns(Model&Ordered $item): array
    {
        return [self::RANK_KEY, ...self::foreignKeys($item), OrderedRelations::DELETED_AT];
    }

    public function concurrencyRevision(): int
    {
        return intval($this->parentRow()->useWritePdo()->value($this->column()));
    }

    /**
     * @return HasMany<Model, Model>
     */
    public function query(): Relation
    {
        return $this->scopedRelation()
            ->orderBy($this->related->qualifyColumn(self::RANK_KEY))
            ->orderBy($this->related->getQualifiedKeyName());
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function reorder(array $ids, null|int $expected): OrderChange
    {
        return $this->parent->getConnection()->transaction(function () use ($ids, $expected): OrderChange {
            self::lockAll([$this]);
            $this->compareConcurrencyRevision($expected);

            $keys = $this->keys();
            $members = array_keys($keys);
            $order = $this->match($members, $ids);

            if ($order === $members) {
                return $this->change([]);
            }

            $this->rewrite($keys, array_combine($order, FractionalIndex::keysBetween(null, null, count($order))));
            $this->refreshConcurrencyRevision();

            $before = array_flip($members);
            $moved = [];

            foreach ($order as $index => $id) {
                if ($before[$id] !== $index) {
                    $moved[$id] = $before[$id] + 1;
                }
            }

            return $this->change($moved);
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

            return $this->change([$id => $previous]);
        }

        $this->write($item, [
            $source->scopedRelation()->getForeignKeyName() => null,
            $this->scopedRelation()->getForeignKeyName() => $this->scopedRelation()->getParentKey(),
            self::RANK_KEY => $this->keyAt($placement),
        ]);
        $this->refreshWith($source);

        return $this->change([$id => null], $source->change([$id => $previous]));
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
    public function lock(): void
    {
        $this->fillParent($this->parentRow()->lock('for no key update')->value($this->column()));
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
        $key = FractionalIndex::keyBetween(...$this->bounds($placement, $exclude));

        if (strlen($key) <= self::MAX_KEY_LENGTH) {
            return $key;
        }

        $this->rebalance();

        return FractionalIndex::keyBetween(...$this->bounds($placement, $exclude));
    }

    /**
     * @internal
     */
    public function position(Model $item): null|int
    {
        $rankKey = $this->related->qualifyColumn(self::RANK_KEY);
        $key = $this->members()->where($this->related->getQualifiedKeyName(), $item->getKey())->value($rankKey);

        if (! is_string($key)) {
            return null;
        }

        return $this->members()->where($rankKey, '<', $key)->count() + 1;
    }

    /**
     * @return list<array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, foreignKey: string, ownerKey: string}>
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
                static fn (array $declaration): bool => $declaration['relation']->getRelated()::class === $item::class,
            ));

            $declaration = $declarations[0] ?? throw new LogicException(sprintf(
                '[%s] declares no ordered relation of [%s].',
                $parent::class,
                $item::class,
            ));

            $memberships[] = [
                'parent' => $parent,
                'revision' => $declaration['declaration']->revision,
                'foreignKey' => $declaration['relation']->getForeignKeyName(),
                'ownerKey' => $declaration['relation']->getLocalKeyName(),
            ];
        }

        return $memberships;
    }

    /**
     * @return array<string, array{declaration: OrdersRelation, relation: HasMany<Model, Model>}>
     */
    private static function declarations(Model $parent): array
    {
        $declarations = [];
        $relations = [];

        foreach (self::attributes($parent, OrdersRelation::class) as $declaration) {
            $relation = self::relation($parent, $declaration->relation);
            $item = $relation->getRelated()::class;

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
        $instances = [];

        for ($class = new ReflectionClass($model); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getAttributes($attribute) as $reflection) {
                $instances[] = $reflection->newInstance();
            }
        }

        return $instances;
    }

    /**
     * @return HasMany<Model, Model>
     */
    private static function relation(Model $parent, string $name): HasMany
    {
        $message = sprintf('[%s::%s()] must return a HasMany relation to an Ordered model.', $parent::class, $name);

        throw_unless(self::declaresHasMany($parent, $name), LogicException::class, $message);

        $relation = $parent->{$name}();

        throw_unless($relation instanceof HasMany && $relation->getRelated() instanceof Ordered, LogicException::class, $message);

        return $relation;
    }

    private static function declaresHasMany(Model $parent, string $name): bool
    {
        if (! method_exists($parent, $name)) {
            return false;
        }

        $method = new ReflectionMethod($parent, $name);
        $type = $method->getReturnType();

        return $method->isPublic()
            && ! $method->isStatic()
            && $type instanceof ReflectionNamedType
            && is_a($type->getName(), HasMany::class, true);
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
     * @return array{null|string, null|string}
     */
    private function bounds(Placement $placement, null|int|string $exclude): array
    {
        $rankKey = $this->related->qualifyColumn(self::RANK_KEY);
        $others = fn (): Builder => $this->members()->when(
            $exclude !== null,
            fn (Builder $query): Builder => $query->where($this->related->getQualifiedKeyName(), '!=', $exclude),
        );

        if ($placement->first) {
            return [null, self::keyOrNull($others()->min($rankKey))];
        }

        if ($placement->before !== null) {
            $anchor = $this->anchor($others(), $placement->before);

            return [self::keyOrNull($others()->where($rankKey, '<', $anchor)->max($rankKey)), $anchor];
        }

        if ($placement->after !== null) {
            $anchor = $this->anchor($others(), $placement->after);

            return [$anchor, self::keyOrNull($others()->where($rankKey, '>', $anchor)->min($rankKey))];
        }

        return [self::keyOrNull($others()->max($rankKey)), null];
    }

    private function anchor(Builder $members, int|string $anchor): string
    {
        $id = $this->anchorId($anchor);
        $key = $id === null ? null : $members->where($this->related->getQualifiedKeyName(), $id)->value($this->related->qualifyColumn(self::RANK_KEY));

        throw_unless(is_string($key), new InvalidAnchor($this, $anchor));

        return $key;
    }

    private function holdsPlace(int|string $id, Placement $placement): bool
    {
        $key = $this->rows([$id])->value($this->related->qualifyColumn(self::RANK_KEY));
        [$lower, $upper] = $this->bounds($placement, $id);

        return is_string($key)
            && ($lower === null || strcmp($key, $lower) > 0)
            && ($upper === null || strcmp($key, $upper) < 0);
    }

    private function rebalance(): void
    {
        $keys = $this->keys();

        $this->rewrite($keys, array_combine(array_keys($keys), FractionalIndex::keysBetween(null, null, count($keys))));
    }

    /**
     * @param  array<int|string, string>  $current
     * @param  array<int|string, string>  $next
     */
    private function rewrite(array $current, array $next): void
    {
        $changed = array_filter($next, static fn (string $key, int|string $id): bool => $current[$id] !== $key, ARRAY_FILTER_USE_BOTH);

        if ($changed === []) {
            return;
        }

        $this->rows(array_keys($changed))->update([self::RANK_KEY => null]);

        foreach ($changed as $id => $key) {
            $this->rows([$id])->update([self::RANK_KEY => $key]);
        }
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
            $byKey[(string) $member] = $member;
        }

        $order = [];

        foreach ($ids as $id) {
            throw_unless(array_key_exists((string) $id, $byKey) && ! array_key_exists((string) $id, $order), new MembershipMismatch($this));

            $order[(string) $id] = $byKey[(string) $id];
        }

        throw_unless(count($order) === count($members), new MembershipMismatch($this));

        return array_values($order);
    }

    /**
     * @param  array<int|string, null|int>  $moved
     */
    private function change(array $moved, null|OrderChange $source = null): OrderChange
    {
        $ids = array_keys($this->keys());
        $positions = array_flip($ids);

        return new OrderChange(
            relation: $this,
            order: array_map(static fn (int|string $id, int $index): array => ['id' => $id, 'position' => $index + 1], $ids, array_keys($ids)),
            changed: array_map(
                static fn (int|string $id, null|int $previous): array => [
                    'id' => $id,
                    'position' => array_key_exists($id, $positions) ? $positions[$id] + 1 : null,
                    'previous_position' => $previous,
                ],
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
        $item->newQueryWithoutScopes()->whereKey($item->getKey())->toBase()->update($attributes);
        $item->forceFill($attributes)->syncOriginalAttributes(array_keys($attributes));
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
    private function keys(): array
    {
        $rankKey = $this->related->qualifyColumn(self::RANK_KEY);
        $key = $this->related->getQualifiedKeyName();

        /** @var array<int|string, string> */
        return $this->members()->whereNotNull($rankKey)->orderBy($rankKey)->orderBy($key)->pluck($rankKey, $key)->all();
    }

    private function anchorId(int|string $anchor): null|int|string
    {
        if (in_array($this->related->getKeyType(), ['int', 'integer'], true)) {
            return is_int($anchor) || (string) (int) $anchor === $anchor ? (int) $anchor : null;
        }

        if (in_array(HasUuids::class, class_uses_recursive($this->related), true)) {
            return Str::isUuid((string) $anchor) ? (string) $anchor : null;
        }

        return (string) $anchor;
    }

    /**
     * @return HasMany<Model, Model>
     */
    private function scopedRelation(): HasMany
    {
        $relation = self::relation($this->parent, $this->relationName);
        $scopes = array_keys($this->related->getGlobalScopes());

        return $relation->withoutGlobalScopes(array_values(array_diff($scopes, [SoftDeletingScope::class])));
    }

    private function members(): Builder
    {
        return $this->scopedRelation()->toBase();
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function rows(array $ids): Builder
    {
        return $this->members()->whereIn($this->related->getQualifiedKeyName(), $ids);
    }

    private function parentRow(): Builder
    {
        return $this->parent->newQueryWithoutScopes()->whereKey($this->parent->getKey())->toBase();
    }

    private function column(): string
    {
        return ConcurrencyRevision::namedColumn((string) $this->revision->value);
    }

    private function fillParent(mixed $revision): void
    {
        $column = $this->column();

        $this->parent->forceFill([$column => intval($revision)])->syncOriginalAttributes([$column]);
    }
}
