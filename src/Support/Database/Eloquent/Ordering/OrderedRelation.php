<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
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
use Support\Database\Eloquent\Ordering\Events\Rebalanced;
use Support\Database\Eloquent\Ordering\Events\Reordered;
use Support\Database\Eloquent\Ordering\Exceptions\InvalidAnchor;
use Support\Database\Eloquent\Ordering\Exceptions\MembershipMismatch;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Throwable;
use WeakMap;

final class OrderedRelation
{
    public const string RANK_KEY = 'rank_key';

    public const int MAX_KEY_LENGTH = 64;

    public const string POSITION = 'position';

    private const string KEY_NAME = 'id';

    private const string PROBE = "\0ordered-relation-membership-probe";

    /** @var array<class-string, array<class-string, list<object>>> */
    private static array $attributes = [];

    /** @var array<string, bool> */
    private static array $declaredRelations = [];

    /** @var array<string, true> */
    private static array $validatedRelations = [];

    /** @var array<string, true> */
    private static array $verifiedMemberships = [];

    /** @var array<string, list<string>> */
    private static array $parentTableColumns = [];

    /** @var null|WeakMap<Model, array<string, mixed>> */
    private static null|WeakMap $prepared = null;

    /** @var null|WeakMap<Model, array<string, mixed>> */
    private static null|WeakMap $preparedRelated = null;

    private readonly string $relationName;

    private readonly RelationKeys $keys;

    /** @var list<string> */
    private readonly array $parentColumns;

    /** @var list<int|string> */
    private array $rebalanced = [];

    public function __construct(
        public readonly Model&OrdersRelations&Revisioned $parent,
        public readonly NamesConcurrencyRevision $revision,
    ) {
        $declarations = self::declarations($parent);
        $declaration = $declarations[(string) $revision->value] ?? throw new LogicException(sprintf(
            '[%s] declares no ordered relation for the [%s] revision.',
            $parent::class,
            $revision->value,
        ));

        $this->relationName = $declaration['declaration']->relation;
        $this->keys = self::describe($declaration['relation']);
        $this->parentColumns = array_values(array_unique(array_merge(
            ...array_map(static fn (array $declared): array => $declared['declaration']->membershipOf($parent->getTable()), array_values($declarations)),
        )));
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

        foreach (self::heldParentsOf($item, $held) as $candidate) {
            if (self::isNamed($membership, $attributes, $candidate)) {
                self::requireConnectionOf($item, $candidate);

                return new self($candidate, $membership['revision']);
            }
        }

        $loaded = self::storedParent($membership, $attributes) ?? throw new LogicException(sprintf(
            '[%s:%s] names a [%s] that does not exist.',
            $item::class,
            $item->getKey(),
            $membership['parent']::class,
        ));

        return new self($loaded, $membership['revision']);
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<Model>  $held
     * @return list<Model&OrdersRelations&Revisioned>
     */
    public static function namedParents(Model&Ordered $item, array $attributes, array $held = []): array
    {
        $connection = $item->getConnection()->getName();
        $parents = [];

        foreach (self::memberships($item) as $membership) {
            if (! $membership['keys']->names($attributes)) {
                continue;
            }

            $parent = array_find(
                self::heldParentsOf($item, $held),
                static fn (Model $candidate): bool => self::isNamed($membership, $attributes, $candidate) && $candidate->getConnection()->getName() === $connection,
            ) ?? self::storedParent($membership, $attributes);

            if ($parent !== null) {
                $parents[] = $parent;
            }
        }

        return $parents;
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

        $locked = self::inLockOrder($unique);

        foreach ($locked as $relation) {
            $relation->lock();
        }

        return $locked;
    }

    /**
     * @internal
     *
     * @param  list<self>  $relations
     */
    public static function raiseAll(array $relations): void
    {
        $unique = [];

        foreach ($relations as $relation) {
            if (! array_any($unique, static fn (self $raised): bool => $raised->is($relation))) {
                $unique[] = $relation;
            }
        }

        foreach (self::inLockOrder($unique) as $relation) {
            $relation->raise();
        }
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
    public static function declaredColumns(Model&Ordered $item): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(static fn (array $membership): array => $membership['columns'], self::memberships($item)),
        )));
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public static function relatedKeyColumns(Model&Ordered $item): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (array $membership): null|string => $membership['keys']->relatedKey, self::memberships($item)),
            is_string(...),
        )));
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public static function watchedColumns(Model&Ordered $item): array
    {
        return [self::RANK_KEY, ...self::membershipColumns($item), ...self::declaredColumns($item), OrderedRelations::DELETED_AT];
    }

    /**
     * @internal
     */
    public static function prepared(Model&Ordered $item): void
    {
        $prepared = self::$prepared ??= new WeakMap;
        $prepared[$item] = self::attributesOf($item, [...self::watchedColumns($item), ...self::relatedKeyColumns($item)]);
    }

    /**
     * @internal
     *
     * @param  list<string>  $columns
     */
    public static function preparedRelated(Model $related, array $columns): void
    {
        $prepared = self::$preparedRelated ??= new WeakMap;
        $prepared[$related] = self::attributesOf($related, $columns);
    }

    /**
     * @internal
     */
    public static function requirePrepared(Model $model): void
    {
        self::requireUnchanged(self::$prepared, $model);
        self::requireUnchanged(self::$preparedRelated, $model);
    }

    /**
     * @internal
     *
     * @return list<array{NamesConcurrencyRevision, list<int|string>}>
     */
    public static function membersBeforeRefilter(Model&OrdersRelations&Revisioned $parent): array
    {
        if (! $parent->exists) {
            return [];
        }

        $stored = null;
        $relations = [];

        foreach (self::attributes($parent, OrdersRelation::class) as $declaration) {
            $columns = $declaration->membershipOf($parent->getTable());

            if ($columns !== [] && $parent->isDirty($columns)) {
                $stored ??= (clone $parent)->setRawAttributes($parent->getRawOriginal(), sync: true);
                $relations[] = new self($stored, $declaration->revision);
            }
        }

        self::lockAll($relations);

        return array_map(static fn (self $relation): array => [$relation->revision, $relation->memberIds()], $relations);
    }

    /**
     * @internal
     */
    public static function usesTimestamps(Model&Ordered $item): bool
    {
        return self::timestamps($item, self::membershipNamedBy($item, $item->getAttributes())['keys']->item ?? $item);
    }

    public function concurrencyRevision(): int
    {
        return intval($this->parentRow()->useWritePdo()->value($this->column()));
    }

    /**
     * @internal
     *
     * @param  array<array-key, Model>  $items
     */
    public static function loadPositions(array $items): void
    {
        $named = [];
        $parents = [];

        foreach ($items as $item) {
            throw_unless($item instanceof Ordered, LogicException::class, sprintf('[%s] is not ordered, so it has no position.', $item::class));

            if (! $item->exists) {
                continue;
            }

            $attributes = $item->getAttributes();
            $membership = self::membershipNamedBy($item, $attributes);

            if ($membership === null) {
                self::fillPosition($item, null);

                continue;
            }

            $key = $item->getKey();

            throw_unless(is_int($key) || is_string($key), LogicException::class, sprintf(
                '[%s] has no [%s], so loadPositions() cannot match it to its position. Load a pivot through [%s::query()], or through a relation that declares withPivot(\'%s\').',
                $item::class,
                $item->getKeyName(),
                self::class,
                self::KEY_NAME,
            ));

            $parent = self::parentNamedBy($membership, $attributes[$membership['keys']->foreignKey]);

            foreach ([...array_values($item->getRelations()), ...$item instanceof Pivot ? [$item->pivotParent] : []] as $model) {
                $candidate = self::asParent($model);

                if ($candidate !== null && self::isNamed($membership, $attributes, $candidate)) {
                    self::requireConnectionOf($item, $candidate);
                    $parents[$parent] ??= $candidate;
                }
            }

            $named[] = [$item, $membership, $parent, $attributes[$membership['keys']->foreignKey]];
        }

        $missing = [];

        foreach ($named as [, $membership, $parent, $ownerKey]) {
            if (! array_key_exists($parent, $parents)) {
                $class = self::parentClassOf($membership);
                $missing[$class] ??= [$membership, []];
                $missing[$class][1][] = $ownerKey;
            }
        }

        foreach ($missing as [$membership, $ownerKeys]) {
            foreach ($membership['parent']->newQueryWithoutScopes()->whereIn($membership['keys']->ownerKey, array_values(array_unique($ownerKeys)))->get() as $model) {
                $candidate = self::asParent($model);

                if ($candidate !== null) {
                    $parents[self::parentNamedBy($membership, $candidate->getAttribute($membership['keys']->ownerKey))] = $candidate;
                }
            }
        }

        $groups = [];

        foreach ($named as [$item, $membership, $parent]) {
            if (! array_key_exists($parent, $parents)) {
                self::fillPosition($item, null);

                continue;
            }

            $group = implode(':', [$item::class, $item->getTable(), (string) $item->getConnectionName(), $membership['parent']::class, (string) $membership['revision']->value]);
            $groups[$group]['relations'][$parent] ??= new self($parents[$parent], $membership['revision']);
            $groups[$group]['items'][] = $item;
        }

        foreach ($groups as ['relations' => $relations, 'items' => $members]) {
            $positions = self::positionsOf(array_values($relations), $members);

            foreach ($members as $member) {
                self::fillPosition($member, $positions[(string) self::keyOf($member)] ?? null);
            }
        }
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

        $selectPositions = $this->selectPositions(...);
        $pivot = $relation instanceof BelongsToMany;

        $relation->getQuery()->macro('withPositions', function (EloquentBuilder $query) use ($selectPositions, $pivot): EloquentBuilder {
            $selectPositions($query, $pivot);

            return $query;
        });

        return $relation
            ->orderBy($this->keys->item->qualifyColumn(self::RANK_KEY))
            ->orderBy($this->keys->item->getQualifiedKeyName());
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function reorder(array $ids, null|int $expected): OrderChange
    {
        return $this->parent->getConnection()->transaction(function () use ($ids, $expected): OrderChange {
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
            $this->raise();

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
            $this->raise();

            return $this->announce($this->change([$id => $previous]));
        }

        $this->write($item, [
            ...$source->keys->detached(),
            ...$this->keys->pointingAt($this->parent),
            self::RANK_KEY => $this->keyAt($placement),
        ]);
        self::raiseAll([$source, $this]);

        return $this->entered($item, $source, $previous);
    }

    /**
     * @internal
     */
    public function entered(Model&Ordered $item, self $source, int $previous): OrderChange
    {
        $id = self::keyOf($item);
        $left = $source->announce($source->change([$id => $previous]));
        $change = $this->change([$id => null], $left);

        if ($change->changed[0]->position !== null) {
            return $this->announce($change);
        }

        $this->announceRebalance($change->revision);

        return $change;
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
    public function refiltered(Model&Ordered $item, null|int $previous): void
    {
        if ($this->memberPosition($item) !== $previous) {
            $this->announce($this->change([self::keyOf($item) => $previous]));
        }
    }

    /**
     * @internal
     *
     * @param  list<int|string>  $before
     */
    public function refilteredFrom(array $before): void
    {
        $after = $this->memberIds();
        $kept = array_flip($after);
        $previous = array_flip($before);
        $moved = [];

        foreach ($before as $index => $id) {
            if (! array_key_exists($id, $kept)) {
                $moved[$id] = $index + 1;
            }
        }

        foreach ($after as $id) {
            if (! array_key_exists($id, $previous)) {
                $moved[$id] = null;
            }
        }

        if ($moved !== []) {
            $this->announce($this->change($moved, order: $after));
        }
    }

    /**
     * @internal
     */
    public function lockRelatedOf(Model&Ordered $item): void
    {
        ['declaration' => $declaration, 'relation' => $relation] = self::declarations($this->parent)[(string) $this->revision->value];

        if (! $relation instanceof BelongsToMany) {
            return;
        }

        $related = $relation->getRelated();

        if (! in_array(SoftDeletes::class, class_uses_recursive($related), true) && $declaration->membershipOf($related->getTable()) === []) {
            return;
        }

        $related->newQueryWithoutScopes()
            ->where($related->qualifyColumn($relation->getRelatedKeyName()), $item->getAttribute($relation->getRelatedPivotKeyName()))
            ->toBase()
            ->lock('for share')
            ->first([$related->qualifyColumn($relation->getRelatedKeyName())]);
    }

    /**
     * @internal
     */
    public function memberPosition(Model $item): null|int
    {
        $position = self::positionsOf([$this], [$item])[(string) self::keyOf($item)] ?? null;

        return is_int($position) || is_string($position) ? (int) $position : null;
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
    public function announceRebalance(null|int $revision = null): void
    {
        if ($this->rebalanced === []) {
            return;
        }

        $ids = $this->rebalanced;
        $this->rebalanced = [];

        event(new Rebalanced($this, $revision ?? $this->concurrencyRevision(), $ids));
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
     * @return list<array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys, columns: list<string>}>
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

            $keys = self::describe($declaration['relation']);

            $memberships[] = [
                'parent' => $parent,
                'revision' => $declaration['declaration']->revision,
                'keys' => $keys,
                'columns' => $declaration['declaration']->membershipOf($keys->item->getTable()),
            ];
        }

        return $memberships;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return null|array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys, columns: list<string>}
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
     * @param  list<Model>  $held
     * @return list<Model&OrdersRelations&Revisioned>
     */
    private static function heldParentsOf(Model&Ordered $item, array $held): array
    {
        return array_values(array_filter(array_map(
            self::asParent(...),
            [...$held, ...$item instanceof Pivot ? [$item->pivotParent] : []],
        )));
    }

    /**
     * @param  array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys, columns: list<string>}  $membership
     * @param  array<string, mixed>  $attributes
     */
    private static function storedParent(array $membership, array $attributes): null|(Model&OrdersRelations&Revisioned)
    {
        return self::asParent($membership['parent']->newQueryWithoutScopes()->where($membership['keys']->ownerKey, $attributes[$membership['keys']->foreignKey])->first());
    }

    /**
     * @param  array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys, columns: list<string>}  $membership
     * @param  array<string, mixed>  $attributes
     */
    private static function isNamed(array $membership, array $attributes, Model $parent): bool
    {
        return $parent::class === $membership['parent']::class
            && self::sameKey($parent->getAttribute($membership['keys']->ownerKey), $attributes[$membership['keys']->foreignKey]);
    }

    /**
     * @param  array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys, columns: list<string>}  $membership
     */
    private static function parentClassOf(array $membership): string
    {
        return implode(':', [$membership['parent']::class, (string) $membership['parent']->getConnectionName(), $membership['keys']->ownerKey]);
    }

    /**
     * @param  array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys, columns: list<string>}  $membership
     */
    private static function parentNamedBy(array $membership, mixed $ownerKey): string
    {
        return self::parentClassOf($membership).':'.(is_int($ownerKey) || is_string($ownerKey) ? (string) $ownerKey : '');
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private static function attributesOf(Model $model, array $columns): array
    {
        $attributes = $model->getAttributes();
        $watched = [];

        foreach ($columns as $column) {
            $watched[$column] = $attributes[$column] ?? null;
        }

        return $watched;
    }

    /**
     * @param  null|WeakMap<Model, array<string, mixed>>  $prepared
     */
    private static function requireUnchanged(null|WeakMap $prepared, Model $model): void
    {
        if ($prepared === null || ! $prepared->offsetExists($model)) {
            return;
        }

        $before = $prepared[$model];
        unset($prepared[$model]);
        $changed = array_keys(array_filter(
            self::attributesOf($model, array_keys($before)),
            static fn (mixed $value, string $column): bool => $value !== $before[$column],
            ARRAY_FILTER_USE_BOTH,
        ));

        throw_if($changed !== [], LogicException::class, sprintf(
            'A listener changed [%s] of [%s:%s] after its order write was prepared.',
            implode('], [', $changed),
            $model::class,
            $model->getKey(),
        ));
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

            self::requireVerifiableMembership($parent, $declaration, $relation);

            $declarations[$declaration->name()] = ['declaration' => $declaration, 'relation' => $relation];
            $relations[$item] = $declaration->relation;
        }

        return $declarations;
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     */
    private static function requireVerifiableMembership(Model $parent, OrdersRelation $declaration, HasMany|MorphMany|BelongsToMany $relation): void
    {
        $name = sprintf('%s::%s()', $parent::class, $declaration->relation);

        if (array_key_exists($name, self::$verifiedMemberships)) {
            return;
        }

        $keys = self::describe($relation);
        $item = $keys->item;
        $related = $relation instanceof BelongsToMany ? $relation->getRelated() : null;
        $tables = array_values(array_unique([$item->getTable(), $parent->getTable(), ...$related === null ? [] : [$related->getTable()]]));

        foreach ($declaration->membership as $column) {
            $table = explode('.', $column)[0];

            throw_unless(substr_count($column, '.') === 1 && in_array($table, $tables, true), LogicException::class, sprintf(
                '[%s] declares the membership column [%s], which names no table of the relation. Qualify it by [%s].',
                $name,
                $column,
                implode('], [', $tables),
            ));
        }

        $implicit = [
            ...array_map($item->qualifyColumn(...), [...$keys->columns(), OrderedRelations::DELETED_AT]),
            ...$related !== null && method_exists($related, 'getQualifiedDeletedAtColumn') ? [$related->getQualifiedDeletedAtColumn()] : [],
        ];
        $wheres = $relation->getBaseQuery()->wheres;

        foreach ([...self::filteredColumns($name, $wheres, $item->getTable(), $related !== null), ...self::parentColumnsRead($parent, $declaration->relation, $keys->ownerKey)] as $column) {
            throw_unless(in_array($column, [...$implicit, ...$declaration->membership], true), LogicException::class, sprintf(
                '[%s] filters its members by [%s], which its membership does not declare.',
                $name,
                $column,
            ));
        }

        self::$verifiedMemberships[$name] = true;
    }

    /**
     * @param  array<array-key, mixed>  $wheres
     * @return list<string>
     */
    private static function filteredColumns(string $name, array $wheres, string $itemTable, bool $pivot, bool $nested = false): array
    {
        $columns = [];

        foreach ($wheres as $where) {
            assert(is_array($where));

            throw_if(! $nested && str_starts_with(strtolower(is_string($where['boolean'] ?? null) ? $where['boolean'] : 'and'), 'or'), LogicException::class, sprintf(
                '[%s] joins a filter to its members with [or], so the filter reaches the rows of other parents.',
                $name,
            ));

            $type = $where['type'] ?? null;

            if ($type === 'Nested' && ($where['query'] ?? null) instanceof Builder) {
                $columns = [...$columns, ...self::filteredColumns($name, $where['query']->wheres, $itemTable, $pivot, nested: true)];

                continue;
            }

            $named = match ($type) {
                'Basic', 'Null', 'NotNull', 'In', 'NotIn' => [$where['column'] ?? null],
                'Column' => [$where['first'] ?? null, $where['second'] ?? null],
                default => throw new LogicException(sprintf(
                    '[%s] filters its members through a clause of type [%s], which a membership declaration cannot verify.',
                    $name,
                    is_string($type) ? $type : get_debug_type($type),
                )),
            };

            foreach ($named as $column) {
                throw_unless(is_string($column), LogicException::class, sprintf('[%s] filters its members by an expression, which a membership declaration cannot verify.', $name));

                if (str_contains($column, '.')) {
                    $columns[] = implode('.', array_slice(explode('.', $column), -2));

                    continue;
                }

                throw_if($pivot, LogicException::class, sprintf(
                    '[%s] filters its members by the unqualified column [%s]. Qualify it by the pivot table or the related table.',
                    $name,
                    $column,
                ));

                $columns[] = $itemTable.'.'.$column;
            }
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private static function parentColumnsRead(Model $parent, string $relation, string $ownerKey): array
    {
        $build = static function (Model $variant) use ($relation): null|array {
            try {
                $query = $variant->{$relation}();
                assert($query instanceof Relation);
                $base = $query->toBase();

                return [$base->toSql(), $base->getBindings()];
            } catch (Throwable) {
                return null;
            }
        };

        $connection = $parent->getConnection();
        $table = $parent->getTable();
        $columns = self::$parentTableColumns[$connection->getName().':'.$table] ??= $connection->getSchemaBuilder()->getColumnListing($table);
        $expected = $build(clone $parent);
        $read = [];

        foreach ($columns as $column) {
            if ($column === $ownerKey || $column === $parent->getKeyName()) {
                continue;
            }

            $variant = clone $parent;
            $variant->setRawAttributes([...$parent->getAttributes(), $column => self::PROBE]);

            if ($build($variant) !== $expected) {
                $read[] = $parent->qualifyColumn($column);
            }
        }

        return $read;
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
            $pivot = (new $class)->setTable($relation->getTable())->setConnection($relation->getParent()->getConnectionName());
            $pivot->timestamps = $relation->hasPivotColumn($pivot->getUpdatedAtColumn());

            return new RelationKeys(
                $pivot,
                $relation->getForeignPivotKeyName(),
                $relation->getParentKeyName(),
                ...$morph,
                relatedKey: $relation->getRelatedPivotKeyName(),
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

    private static function timestamps(Model $item, Model $template): bool
    {
        return ($item instanceof Pivot ? $template : $item)->usesTimestamps();
    }

    private static function fillPosition(Model $item, mixed $position): void
    {
        $item->forceFill([self::POSITION => $position])->syncOriginalAttributes([self::POSITION]);
    }

    private static function keyOf(Model $item): int|string
    {
        $key = $item->getKey();

        throw_unless(is_int($key) || is_string($key), LogicException::class, sprintf('[%s] has no key.', $item::class));

        return $key;
    }

    /**
     * @param  array<array-key, self>  $relations
     * @return list<self>
     */
    private static function inLockOrder(array $relations): array
    {
        usort($relations, static fn (self $first, self $second): int => [$first->parent->getMorphClass(), $first->parent->getKey()]
            <=> [$second->parent->getMorphClass(), $second->parent->getKey()]);

        return $relations;
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
        $rewritten = $this->rewrite($keys, array_combine(array_keys($keys), FractionalIndex::keysBetween(null, null, count($keys))));

        $this->rebalanced = array_values(array_unique([...$this->rebalanced, ...$rewritten]));
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

        $ids = array_keys($changed);
        $copies = OrderWrite::copies($this->keys->item, $ids, $this->keys->item->usesTimestamps());

        $this->keys->item->newQueryWithoutScopes()->toBase()->whereIn($this->keys->item->getQualifiedKeyName(), $ids)->update([self::RANK_KEY => null]);

        foreach ($changed as $id => $key) {
            if (array_key_exists($id, $copies)) {
                OrderWrite::save($copies[$id], [self::RANK_KEY => $key]);
            }
        }

        return $ids;
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
        $this->announceRebalance($change->revision);
        event(new Reordered($change));

        return $change;
    }

    /**
     * @param  array<int|string, null|int>  $moved
     * @param  null|list<int|string>  $order
     */
    private function change(array $moved, null|OrderChange $source = null, null|array $order = null): OrderChange
    {
        $ids = $order ?? $this->memberIds();
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
        $copy = OrderWrite::copy($item, self::timestamps($item, $this->keys->item))
            ?? throw new LogicException(sprintf('[%s:%s] has no row to write.', $item::class, self::keyOf($item)));
        $written = OrderWrite::save($copy, $attributes);
        $item->forceFill($written)->syncOriginalAttributes(array_keys($written));

        if ($item instanceof Revisioned) {
            $item->refreshConcurrencyRevisions();
        }
    }

    private function raise(): void
    {
        $column = $this->column();
        $copy = OrderWrite::copy($this->parent, timestamps: false);

        if ($copy !== null) {
            OrderWrite::save($copy, [$column => new Expression(sprintf('%s + 1', $copy->getConnection()->getQueryGrammar()->wrap($column)))]);
        }

        $this->fillParent($this->parentRow()->useWritePdo()->value($column));
    }

    /**
     * @return list<int|string>
     */
    private function memberIds(): array
    {
        return array_keys($this->rankKeys($this->members()));
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

    /**
     * @param  EloquentBuilder<Model>  $query
     */
    private function selectPositions(EloquentBuilder $query, bool $pivot): void
    {
        $base = $query->getQuery();
        $table = $this->keys->item->getTable();
        $numbered = $this->numbered($this->members(), [$this->keys->item->qualifyColumn('*')]);
        $sql = sprintf('(%s) as %s', $numbered->toSql(), $base->getGrammar()->wrapTable($table));
        $numbers = static fn (mixed $table): bool => $table instanceof Expression && $table->getValue($base->getGrammar()) === $sql;

        if (! $pivot) {
            if (! $numbers($base->from)) {
                $base->fromRaw($sql, $numbered->getBindings());
            }

            return;
        }

        $joins = $base->joins ?? [];

        if (array_any($joins, static fn (JoinClause $join): bool => $numbers($join->table))) {
            return;
        }

        $index = array_find_key($joins, static fn (JoinClause $join): bool => $join->table === $table)
            ?? throw new LogicException(sprintf('[%s::%s()] does not join the pivot table [%s].', $this->parent::class, $this->relationName, $table));

        $join = clone $joins[$index];
        $join->table = new Expression($sql);
        $base->joins[$index] = $join;
        $base->setBindings([...$numbered->getBindings(), ...$base->getRawBindings()['join']], 'join');

        if ($base->columns === null) {
            $query->select($query->getModel()->qualifyColumn('*'));
        }

        $base->addSelect(sprintf('%s as pivot_%s', $this->keys->item->qualifyColumn(self::POSITION), self::POSITION));
    }

    /**
     * @param  non-empty-list<self>  $relations
     * @param  list<Model>  $items
     * @return array<string, mixed>
     */
    private static function positionsOf(array $relations, array $items): array
    {
        $keys = $relations[0]->keys;
        $rows = array_map(static fn (self $relation): Builder => $relation->members()->select([
            $keys->item->getQualifiedKeyName(),
            $keys->item->qualifyColumn($keys->foreignKey),
            $keys->item->qualifyColumn(self::RANK_KEY),
        ]), $relations);
        $members = array_shift($rows);

        foreach ($rows as $row) {
            $members->unionAll($row);
        }

        $table = $keys->item->getTable();
        $numbered = $relations[0]->numbered($members->newQuery()->fromSub($members, $table), [$keys->item->getQualifiedKeyName()]);

        /** @var array<string, mixed> */
        return $numbered->newQuery()
            ->fromSub($numbered, $table)
            ->whereIn($keys->item->getQualifiedKeyName(), array_map(self::keyOf(...), $items))
            ->pluck(self::POSITION, $keys->item->getKeyName())
            ->all();
    }

    /**
     * @param  list<string>  $columns
     */
    private function numbered(Builder $members, array $columns): Builder
    {
        $grammar = $members->getGrammar();
        $rankKey = $grammar->wrap($this->keys->item->qualifyColumn(self::RANK_KEY));

        return $members->select($columns)->selectRaw(sprintf(
            'case when %1$s is not null then row_number() over (partition by %2$s order by %1$s, %3$s) end as %4$s',
            $rankKey,
            $grammar->wrap($this->keys->item->qualifyColumn($this->keys->foreignKey)),
            $grammar->wrap($this->keys->item->getQualifiedKeyName()),
            $grammar->wrap(self::POSITION),
        ));
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
        $declared = array_diff_key(array_intersect_key($row, array_flip($this->parentColumns)), $this->parent->getDirty());

        if ($declared !== []) {
            $this->parent->setRawAttributes(array_replace($this->parent->getAttributes(), $declared));
            $this->parent->syncOriginalAttributes(array_keys($declared));
        }

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
