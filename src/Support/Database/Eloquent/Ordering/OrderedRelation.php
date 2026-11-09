<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Closure;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Stringable;
use Support\Database\Eloquent\Concurrency\Attributes\NamedConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\LocksParentsFirst;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Scopes\SelectsConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Attributes\OrderedThrough;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Events\Rebalanced;
use Support\Database\Eloquent\Ordering\Events\Reordered;
use Support\Database\Eloquent\Ordering\Exceptions\ContendedOrder;
use Support\Database\Eloquent\Ordering\Exceptions\InvalidAnchor;
use Support\Database\Eloquent\Ordering\Exceptions\MembershipMismatch;
use Support\Database\Eloquent\Ordering\Exceptions\NotAMember;
use Support\Database\Eloquent\Ordering\Exceptions\ParentUnavailability;
use Support\Database\Eloquent\Ordering\Exceptions\UnavailableParent;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Support\Database\Eloquent\Ordering\Provides\OrdersThroughPivots;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Support\Database\Eloquent\Ordering\Scopes\SelectsPosition;
use Throwable;
use WeakMap;

/**
 * @internal
 */
final class OrderedRelation
{
    public const string RANK_KEY = 'rank_key';

    public const int MAX_KEY_LENGTH = 64;

    public const string POSITION = 'position';

    private const string KEY_NAME = 'id';

    private const int LOCK_ATTEMPTS = 5;

    private const string PROBE = "\0ordered-relation-membership-probe";

    private const string MEMBER = 'ordering_member';

    private const string MEMBER_PARENT = 'ordering_parent';

    private const string MEMBER_RELATED = 'ordering_related';

    private const string OUTER = 'ordering_outer';

    /** @var array<class-string, array<class-string, list<object>>> */
    private static array $attributes = [];

    /** @var array<class-string, list<array{string, OrdersRelation}>> */
    private static array $orderDeclarations = [];

    /** @var array<string, bool> */
    private static array $declaredRelations = [];

    /** @var array<string, true> */
    private static array $validatedRelations = [];

    /** @var array<string, true> */
    private static array $verifiedMemberships = [];

    /** @var array<string, list<string>> */
    private static array $parentTableColumns = [];

    /** @var array<string, array<string, string>> */
    private static array $membershipPairs = [];

    /** @var array<class-string, array<string, Model&Ordered>> */
    private static array $orderedPivots = [];

    /** @var null|WeakMap<Model, array<string, mixed>> */
    private static null|WeakMap $prepared = null;

    /** @var null|WeakMap<Model, array<string, mixed>> */
    private static null|WeakMap $preparedRelated = null;

    /** @var null|WeakMap<Model, true> */
    private static null|WeakMap $parentsLockedFirst = null;

    /** @var list<self> */
    private static array $creating = [];

    /** @var null|WeakMap<Model, array{self, string}> */
    private static null|WeakMap $inFlight = null;

    private readonly string $relationName;

    private readonly RelationKeys $keys;

    /** @var list<string> */
    private readonly array $parentColumns;

    /** @var list<int|string> */
    private array $rebalanced = [];

    private null|ParentUnavailability $unavailable = null;

    /** @var list<string> */
    private array $pendingKeys = [];

    private null|Model|int|string $claimed = null;

    /** @var list<int|string> */
    private array $created = [];

    public function __construct(
        public readonly Model&OrdersRelations&Revisioned $parent,
        public readonly NamesConcurrencyRevision $revision,
    ) {
        $declaration = self::declaration($parent, $revision);

        $this->relationName = $declaration['method'];
        $this->keys = self::describe($declaration['relation']);
        $this->parentColumns = self::parentMembershipColumns($parent);
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<Model>  $held
     */
    public static function of(Model&Ordered $item, array $attributes, array $held = []): null|self
    {
        return self::resolve($item, $attributes, $held, static fn (array $membership): never => throw new LogicException(sprintf(
            '[%s:%s] names a [%s] that does not exist.',
            $item::class,
            $item->getKey(),
            $membership['parent']::class,
        )));
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<Model>  $held
     */
    public static function sourceOf(Model&Ordered $item, array $attributes, array $held = []): null|self
    {
        return self::resolve($item, $attributes, $held, static fn (): null => null);
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<Model>  $held
     */
    public static function destinationOf(Model&Ordered $item, array $attributes, array $held = []): null|self
    {
        return self::resolve($item, $attributes, $held, static fn (array $membership): self => new self(self::missingParent($membership, $attributes), $membership['revision']));
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
     * @param  Relation<*, *, *>  $relation
     */
    public static function ordering(Relation $relation): self
    {
        $item = $relation instanceof HasMany || $relation instanceof MorphMany || $relation instanceof BelongsToMany ? self::itemClass($relation) : null;
        $parent = self::asParent($relation->getParent());
        $declaration = $item === null || $parent === null
            ? null
            : array_find(self::declarations($parent), static fn (array $declared): bool => self::itemClass($declared['relation']) === $item && self::sameKeys($relation, $declared['relation']));

        if ($declaration === null) {
            throw new LogicException(sprintf('The [%s] relation of [%s] to [%s] orders nothing.', $relation::class, $relation->getParent()::class, $item ?? $relation->getRelated()::class));
        }

        return new self($parent, $declaration['declaration']->revision);
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

        foreach ($relations as $relation) {
            if ($relation !== null) {
                $relation->unavailable = $unique[spl_object_id($relation->parent)]->unavailable;
            }
        }

        return $locked;
    }

    /**
     * @internal
     */
    public static function lockParentsFirst(Model&LocksParentsFirst $model): void
    {
        $locked = self::$parentsLockedFirst ??= new WeakMap;

        if ($locked->offsetExists($model)) {
            return;
        }

        self::lockInOrder($model->parentsToLockFirst());
        $locked[$model] = true;
    }

    /**
     * @internal
     */
    public static function lockParentsFirstUnlessLocked(Model&LocksParentsFirst $model): void
    {
        $locked = self::$parentsLockedFirst;

        if ($locked !== null && $locked->offsetExists($model)) {
            unset($locked[$model]);

            return;
        }

        self::lockInOrder($model->parentsToLockFirst());
    }

    /**
     * @internal
     */
    public static function forgetParentsLockedFirst(Model $model): void
    {
        self::$parentsLockedFirst?->offsetUnset($model);
    }

    /**
     * @internal
     *
     * @return list<array{string, OrdersRelation}>
     */
    public static function orderDeclarations(Model $model): array
    {
        if (! array_key_exists($model::class, self::$orderDeclarations)) {
            $declarations = [];

            foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(NamedConcurrencyRevision::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    $declaration = $attribute->newInstance();

                    if ($declaration instanceof OrdersRelation) {
                        $declarations[] = [$method->getName(), $declaration];
                    }
                }
            }

            self::$orderDeclarations[$model::class] = $declarations;
        }

        return self::$orderDeclarations[$model::class];
    }

    /**
     * @internal
     *
     * @return EloquentBuilder<*>
     */
    public static function memberQuery(Model $parent, NamesConcurrencyRevision $revision): EloquentBuilder
    {
        $relation = self::declaration($parent, $revision)['relation'];
        $item = self::describe($relation)->item;
        $rankKey = $item->qualifyColumn(self::RANK_KEY);
        $members = $relation->getQuery()->clone();
        self::liveMembersOf($relation, $members);

        return $members
            ->reorder()
            ->whereNotNull($rankKey)
            ->orderBy($rankKey)
            ->orderBy($item->getQualifiedKeyName());
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
        $prepared[$related] = [...$prepared[$related] ?? [], ...self::attributesOf($related, $columns)];
    }

    /**
     * @internal
     */
    public static function preparedParent(Model&OrdersRelations&Revisioned $parent): void
    {
        $columns = self::parentMembershipColumns($parent);

        if ($parent->exists && $columns !== []) {
            self::preparedRelated($parent, $columns);
        }
    }

    /**
     * @internal
     */
    public static function refilters(Model&OrdersRelations&Revisioned $parent): bool
    {
        $columns = self::parentMembershipColumns($parent);

        return $parent->exists && $columns !== [] && $parent->isDirty($columns);
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

        foreach (self::orderDeclarations($parent) as [, $declaration]) {
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

    /**
     * @internal
     */
    public static function positionColumn(Model&Ordered $item, string $qualifier, Connection $connection): null|string
    {
        $grammar = $connection->getQueryGrammar();
        $branches = array_map(
            static fn (array $membership): string => sprintf('when %s then (%s)', self::namesMembership($membership, $qualifier, $grammar), self::countedMembers($membership, $qualifier, $connection)),
            self::memberships($item, $connection->getName()),
        );

        return $branches === [] ? null : sprintf('case %s end', implode(' ', $branches));
    }

    /**
     * @internal
     *
     * @return array<string, Model&Ordered>
     */
    public static function orderedPivots(Model $model): array
    {
        if (! array_key_exists($model::class, self::$orderedPivots)) {
            $parents = array_map(static fn (string $class): Model => new $class, self::orderedThrough($model));
            $pivots = [];

            if (self::ordersPivots($model)) {
                $parents[] = $model;
            }

            foreach (array_filter(array_map(self::asParent(...), $parents)) as $parent) {
                foreach (self::declarations($parent) as ['relation' => $relation]) {
                    $pivot = $relation instanceof BelongsToMany ? self::describe($relation)->item : null;

                    if ($pivot instanceof Ordered && ($parent === $model || $relation->getRelated()::class === $model::class)) {
                        $pivots[$relation->getTable()] ??= $pivot;
                    }
                }
            }

            self::$orderedPivots[$model::class] = $pivots;
        }

        return self::$orderedPivots[$model::class];
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function arrange(array $ids, null|string $expected): OrderChange
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
        $previous = $source->requireMember($item);

        if ($this->is($source)) {
            if ($this->holdsPlace($id, $placement)) {
                return $this->change([]);
            }

            $this->write($item, [self::RANK_KEY => $this->keyAt($placement, $id)]);

            return $this->announce($this->change([$id => $previous]));
        }

        $this->refuseUnavailable();
        $this->write($item, [
            ...$source->keys->detached(),
            ...$this->keys->pointingAt($this->parent),
            self::RANK_KEY => $this->keyAt($placement),
        ]);

        return $this->entered($item, $source, $previous);
    }

    /**
     * @param  list<Model>  $alsoLock
     * @return Collection<int, Model>
     */
    public function lockedMembers(array $alsoLock): Collection
    {
        throw_if($this->parent->getConnection()->transactionLevel() === 0, LogicException::class, sprintf(
            'Lock the members of [%s:%s] inside a transaction, because its row locks end with the statement otherwise.',
            $this->parent->getMorphClass(),
            $this->parent->getKey(),
        ));

        $this->requireLockable($alsoLock);

        return $this->lockWith(
            static fn (): null => null,
            static fn (): array => $alsoLock,
            fn (): Collection => $this->unavailable === ParentUnavailability::Missing ? new Collection : $this->currentMembers(),
        );
    }

    /**
     * @internal
     *
     * @template TLocked
     *
     * @param  Closure(RuntimeException): TLocked  $lock
     * @return TLocked
     */
    public static function retryWhileMoved(Model $contended, Closure $lock): mixed
    {
        $moved = new RuntimeException;

        for ($attempt = 1; $attempt <= self::LOCK_ATTEMPTS; $attempt++) {
            try {
                return $lock($moved);
            } catch (RuntimeException $exception) {
                throw_unless($exception === $moved, $exception);
            }
        }

        throw new ContendedOrder($contended, self::LOCK_ATTEMPTS);
    }

    /**
     * @param  iterable<mixed>  $items
     */
    public function moveIn(iterable $items, null|string $expected, Placement $placement): OrderChange
    {
        throw_unless($this->parent->exists, LogicException::class, sprintf('[%s] must be saved before it orders a member.', $this->parent::class));
        throw_if($placement->parent !== null, LogicException::class, 'Name the destination of a move in by its relation, not in the placement.');

        $items = $this->movable($items);

        return $this->parent->getConnection()->transaction(function () use ($items, $expected, $placement): OrderChange {
            $original = array_map(static fn (Model $item): array => $item->getRawOriginal(), $items);
            [$rows, $sources] = $this->lockWith(
                fn (): array => $this->storedRowsOf($items),
                fn (array $rows): array => array_map(static fn (self $source): Model => $source->parent, $this->sourcesOf($items, $rows, [])[1]),
                function (array $rows, array $parents) use ($items): array {
                    [$sources, $unique] = $this->sourcesOf($items, $rows, $parents);

                    foreach ($unique as $source) {
                        $source->lock();
                    }

                    return [$rows, $sources];
                },
            );

            if ($expected !== null) {
                $this->compareExpected($items, $original, $sources, $expected);
            }

            $previous = $this->requireMembers($items, $sources);

            return $this->moveMembersIn($items, $placement, $rows, $sources, $previous, $original);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return Collection<int, Model>
     */
    public function createInOrder(array $records): Collection
    {
        if ($records === []) {
            return new Collection;
        }

        throw_unless($this->parent->exists, LogicException::class, sprintf('[%s] must be saved before it orders a member.', $this->parent::class));

        return $this->parent->getConnection()->transaction(function () use ($records): Collection {
            self::lockAll([$this]);
            $this->refuseUnavailable();

            $relation = self::relation($this->parent, $this->relationName);
            $this->pendingKeys = $this->keysAt(Placement::last(), [], count($records));
            $this->created = [];
            self::$creating[] = $this;

            try {
                $models = array_map(fn (array $record): Model => $this->createClaimed($relation, $record), $records);
            } finally {
                self::$creating = array_values(array_filter(self::$creating, fn (self $relation): bool => $relation !== $this));
                $this->pendingKeys = [];
            }

            $this->announceCreated($this->created);

            return new Collection($models);
        });
    }

    /**
     * @param  list<int|string>  $created
     */
    private function announceCreated(array $created): void
    {
        $order = $this->memberIds();
        $members = array_flip($order);
        $change = $this->change(array_fill_keys(array_values(array_filter($created, static fn (int|string $id): bool => array_key_exists($id, $members))), null), order: $order);
        $this->created = [];

        if ($change->changed === []) {
            $this->announceRebalance($change->digest);
        } else {
            $this->announce($change);
        }
    }

    /**
     * @internal
     */
    public function requireMember(Model&Ordered $item): int
    {
        return $this->memberPosition($item) ?? throw new NotAMember($item::class, [self::keyOf($item)]);
    }

    /**
     * @internal
     */
    public function refuseUnavailable(): void
    {
        if ($this->unavailable !== null) {
            throw new UnavailableParent($this->parent, $this->unavailable);
        }
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

        $this->announceRebalance($change->digest);

        return $change;
    }

    /**
     * @internal
     */
    public function keyForCreate(Model&Ordered $item, Placement $placement): string
    {
        $key = $this->batchOf($item)?->nextPendingKey() ?? $this->keyAt($placement);
        self::$inFlight ??= new WeakMap;
        self::$inFlight[$item] = [$this, $key];

        return $key;
    }

    /**
     * @internal
     */
    public function placed(Model&Ordered $item): void
    {
        unset(self::$inFlight[$item]);
        $batch = $this->batchOf($item);

        if ($batch !== null) {
            $batch->created[] = self::keyOf($item);

            return;
        }

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

        $related->newModelQuery()
            ->where($related->qualifyColumn($relation->getRelatedKeyName()), self::relatedKeyOf($relation, $item))
            ->toBase()
            ->lock('for share')
            ->first([$related->qualifyColumn($relation->getRelatedKeyName())]);
    }

    /**
     * @internal
     */
    public function memberPosition(Model $item): null|int
    {
        if ($this->unavailable === ParentUnavailability::Missing) {
            return null;
        }

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
    public function compareConcurrencyRevision(null|string $expected): void
    {
        if ($expected !== null && $this->parent->concurrencyRevision($this->revision) !== $expected) {
            throw new StaleConcurrencyRevision($this->parent, $this->revision, $expected);
        }
    }

    /**
     * @internal
     */
    public function announceRebalance(null|string $revision = null): void
    {
        if ($this->rebalanced === []) {
            return;
        }

        $ids = $this->rebalanced;
        $this->rebalanced = [];

        event(new Rebalanced($this->parent, $this->revision, $revision ?? $this->parent->concurrencyRevision($this->revision), $ids));
    }

    /**
     * @internal
     */
    public function keyAt(Placement $placement, null|int|string $exclude = null): string
    {
        $excluded = $exclude === null ? [] : [$exclude];
        $key = FractionalIndex::keyBetween(...$this->bounds($placement, $excluded, $this->indexedRows(...)));

        if (strlen($key) <= self::MAX_KEY_LENGTH) {
            return $key;
        }

        $this->rebalance();

        return FractionalIndex::keyBetween(...$this->bounds($placement, $excluded, $this->indexedRows(...)));
    }

    /**
     * @internal
     *
     * @param  list<array<string, mixed>>  $left
     */
    public static function followParent(Model&Ordered $item, array $left, Model $parent): void
    {
        $follows = static fn (mixed $model): bool => $model instanceof Model
            && ! $model->is($parent)
            && array_any($left, static fn (array $attributes): bool => self::names($item, $attributes, $model));

        foreach ($item->getRelations() as $name => $related) {
            if (! $follows($related)) {
                continue;
            }

            if ($related::class === $parent::class) {
                $item->setRelation($name, $parent);
            } else {
                $item->unsetRelation($name);
            }
        }

        if ($item instanceof Pivot && $follows($item->pivotParent)) {
            $item->pivotParent = $parent;
        }
    }

    /**
     * @param  array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys, columns: list<string>}  $membership
     */
    private static function namesMembership(array $membership, string $qualifier, Grammar $grammar): string
    {
        $keys = $membership['keys'];
        $named = sprintf('%s is not null', $grammar->wrap($qualifier.'.'.$keys->foreignKey));

        return $keys->morphType === null
            ? $named
            : sprintf('%s and %s = %s', $named, $grammar->wrap($qualifier.'.'.$keys->morphType), $grammar->escape($keys->morphClass));
    }

    /**
     * @param  array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys, columns: list<string>}  $membership
     */
    private static function countedMembers(array $membership, string $qualifier, Connection $connection): string
    {
        ['method' => $method, 'relation' => $relation] = self::declaration($membership['parent'], $membership['revision']);

        return self::memberCount($membership['parent'], $method, $relation, $membership['keys'], $qualifier, $connection);
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     */
    private static function memberCount(Model $parent, string $method, HasMany|MorphMany|BelongsToMany $relation, RelationKeys $keys, string $qualifier, Connection $connection): string
    {
        $key = $keys->item->getKeyName();
        $inner = self::innerAliases($qualifier)[0];
        $member = static fn (string $column): string => $inner.'.'.$column;
        $outer = static fn (string $column): string => $qualifier.'.'.$column;
        $members = self::liveMembers($parent, $method, $relation, $keys, $qualifier, $connection);
        $grammar = $members->getGrammar();

        $members
            ->whereColumn($member(self::RANK_KEY), '<=', $outer(self::RANK_KEY))
            ->whereRaw(sprintf(
                '(%s, %s) <= (%s, %s)',
                $grammar->wrap($member(self::RANK_KEY)),
                $grammar->wrap($member($key)),
                $grammar->wrap($outer(self::RANK_KEY)),
                $grammar->wrap($outer($key)),
            ))
            ->selectRaw(sprintf('case when bool_or(%s = %s) then count(*) end', $grammar->wrap($member($key)), $grammar->wrap($outer($key))));

        $literals = array_map(self::untypedLiteral(...), $connection->prepareBindings($members->getBindings()));

        return preg_replace_callback(
            '/\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|\?\??/',
            static function (array $match) use (&$literals): string {
                return $match[0] === '?' ? array_shift($literals) ?? throw new LogicException('A position count holds more placeholders than bindings.') : $match[0];
            },
            $members->toSql(),
        ) ?? throw new LogicException('A position count could not render its bindings.');
    }

    private static function untypedLiteral(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_scalar($value) || $value instanceof Stringable => "'".str_replace("'", "''", (string) $value)."'",
            default => throw new LogicException(sprintf('A position count cannot render a [%s] binding as a literal.', get_debug_type($value))),
        };
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     */
    private static function liveMembers(Model $parent, string $method, HasMany|MorphMany|BelongsToMany $relation, RelationKeys $keys, string $qualifier, Connection $connection): Builder
    {
        $item = $keys->item;
        [$inner, $owner, $relatedAlias] = self::innerAliases($qualifier);
        $member = static fn (string $column): string => $inner.'.'.$column;
        $outer = static fn (string $column): string => $qualifier.'.'.$column;
        $aliases = [$parent->getTable() => $owner];

        $members = $connection->query()
            ->from($item->getTable().' as '.$inner)
            ->join($parent->getTable().' as '.$owner, $owner.'.'.$keys->ownerKey, '=', $member($keys->foreignKey))
            ->whereColumn($member($keys->foreignKey), '=', $outer($keys->foreignKey))
            ->whereNotNull($member(self::RANK_KEY));

        if ($keys->morphType !== null) {
            $members->whereColumn($member($keys->morphType), '=', $outer($keys->morphType));
        }

        if ($relation instanceof BelongsToMany) {
            $related = $relation->getRelated();
            $aliases[$related->getTable()] = $relatedAlias;
            $members->join($related->getTable().' as '.$relatedAlias, $relatedAlias.'.'.$relation->getRelatedKeyName(), '=', $member($relation->getRelatedPivotKeyName()));
            self::whereLive($members, $related, $relatedAlias);
        }

        $aliases[$item->getTable()] = $inner;
        self::whereLive($members, $item, $inner);
        self::whereLive($members, $parent, $owner);

        $filters = self::membershipFilters($parent, $method, $aliases, $inner, $owner);

        if ($filters->wheres !== []) {
            $members->addNestedWhereQuery($filters);
        }

        return $members;
    }

    /**
     * @return array{string, string, string}
     */
    private static function innerAliases(string $qualifier): array
    {
        $aliases = [self::MEMBER, self::MEMBER_PARENT, self::MEMBER_RELATED];

        return in_array($qualifier, $aliases, true) ? array_map(static fn (string $alias): string => $alias.'_inner', $aliases) : $aliases;
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     * @param  EloquentBuilder<*>  $members
     * @param  list<string>  $kept
     */
    private static function liveMembersOf(HasMany|MorphMany|BelongsToMany $relation, EloquentBuilder $members, array $kept = []): void
    {
        $related = $relation->getRelated();
        $item = self::describe($relation)->item;
        $members->withoutGlobalScopes(array_values(array_diff(array_keys($related->getGlobalScopes()), $kept)));
        self::whereLive($members->getQuery(), $item, $item->getTable());

        if ($relation instanceof BelongsToMany) {
            self::whereLive($members->getQuery(), $related, $related->getTable());
        }
    }

    private static function whereLive(Builder $query, Model $model, string $alias): void
    {
        $deletedAt = in_array(SoftDeletes::class, class_uses_recursive($model), true) && method_exists($model, 'getDeletedAtColumn') ? $model->getDeletedAtColumn() : null;

        if (is_string($deletedAt)) {
            $query->whereNull($alias.'.'.$deletedAt);
        }
    }

    /**
     * @param  array<string, string>  $aliases
     */
    private static function membershipFilters(Model $parent, string $method, array $aliases, string $member, string $owner): Builder
    {
        $pairs = self::$membershipPairs[sprintf('%s::%s()', $parent::class, $method)] ?? [];
        $base = self::probedRelation($parent, $method, array_keys($pairs))->getBaseQuery();
        $filters = $base->newQuery();
        $probes = array_map(self::probe(...), array_keys($pairs));

        self::copyFilters($base->wheres, $filters, $pairs, $aliases, $member, $owner);

        return $filters->setBindings(array_values(array_filter(
            $base->getRawBindings()['where'],
            static fn (mixed $binding): bool => ! in_array($binding, $probes, true),
        )));
    }

    /**
     * @param  array<array-key, mixed>  $wheres
     * @param  array<string, string>  $pairs
     * @param  array<string, string>  $aliases
     */
    private static function copyFilters(array $wheres, Builder $into, array $pairs, array $aliases, string $member, string $owner): void
    {
        $probes = array_combine(array_map(self::probe(...), array_keys($pairs)), array_keys($pairs));
        $stack = [[array_values($wheres), $into, null]];

        while ($stack !== []) {
            [$remaining, $target, $group] = array_pop($stack);

            if ($remaining === [] && $group !== null) {
                $group[0]->addNestedWhereQuery($target, $group[1]);
            } elseif ($remaining !== []) {
                $where = array_shift($remaining);
                assert(is_array($where));
                $boolean = is_string($where['boolean'] ?? null) ? $where['boolean'] : 'and';
                $stack[] = [$remaining, $target, $group];
                $nested = self::copyFilter($where, $boolean, $target, $probes, $aliases, $member, $owner);

                if ($nested !== null) {
                    $stack[] = [array_values($nested->wheres), $target->forNestedWhere(), [$target, $boolean]];
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $where
     * @param  array<string, string>  $probes
     * @param  array<string, string>  $aliases
     */
    private static function copyFilter(array $where, string $boolean, Builder $into, array $probes, array $aliases, string $member, string $owner): null|Builder
    {
        $value = $where['value'] ?? null;
        $paired = is_string($value) ? $probes[$value] ?? null : null;
        $alias = static fn (mixed $column): string => self::aliasedColumn($column, $aliases, $member);

        if (($where['type'] ?? null) === 'Nested') {
            return ($where['query'] ?? null) instanceof Builder ? $where['query'] : throw new LogicException('A nested membership filter holds no query.');
        }

        match ($where['type'] ?? null) {
            'Basic' => $paired === null
                ? $into->where($alias($where['column'] ?? null), is_string($where['operator'] ?? null) ? $where['operator'] : '=', $value, $boolean)
                : $into->whereRaw(sprintf('%s is not distinct from %s', $into->getGrammar()->wrap($alias($where['column'] ?? null)), $into->getGrammar()->wrap($owner.'.'.$paired)), [], $boolean),
            'Null' => $into->whereNull($alias($where['column'] ?? null), $boolean),
            'NotNull' => $into->whereNotNull($alias($where['column'] ?? null), $boolean),
            'In' => $into->whereIn($alias($where['column'] ?? null), is_array($where['values'] ?? null) ? $where['values'] : [], $boolean),
            'NotIn' => $into->whereNotIn($alias($where['column'] ?? null), is_array($where['values'] ?? null) ? $where['values'] : [], $boolean),
            'Column' => $into->whereColumn($alias($where['first'] ?? null), is_string($where['operator'] ?? null) ? $where['operator'] : '=', $alias($where['second'] ?? null), $boolean),
            default => throw new LogicException(sprintf('A membership filter of type [%s] cannot be reproduced for each row.', get_debug_type($where['type'] ?? null))),
        };

        return null;
    }

    /**
     * @param  array<string, string>  $aliases
     */
    private static function aliasedColumn(mixed $column, array $aliases, string $member): string
    {
        throw_unless(is_string($column), LogicException::class, 'A membership filter names a column by an expression.');

        $segments = explode('.', $column);
        $name = array_pop($segments);
        $table = array_pop($segments);

        return ($table === null ? $member : ($aliases[$table] ?? $table)).'.'.$name;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $nulled
     * @return Relation<*, *, *>
     */
    private static function probedRelation(Model $parent, string $method, array $columns, array $nulled = []): Relation
    {
        $variant = self::probed($parent, $columns, $nulled);

        try {
            $relation = Relation::noConstraints(static fn (): mixed => $variant->{$method}());
        } catch (Throwable $exception) {
            $read = implode('], [', array_map($parent->qualifyColumn(...), $columns));

            throw new LogicException(sprintf(
                '[%s::%s()] filters its members by the parent column [%s] in a form other than [<item column> = %s], so a position query cannot reproduce the filter for each row.',
                $parent::class,
                $method,
                $read,
                $read,
            ), previous: $exception);
        }

        throw_unless($relation instanceof Relation, LogicException::class, sprintf('[%s::%s()] returns no relation.', $parent::class, $method));

        return $relation;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $nulled
     */
    private static function probed(Model $parent, array $columns, array $nulled = []): Model
    {
        $variant = (clone $parent)->mergeCasts(array_fill_keys($columns, 'string'));
        $variant->setRawAttributes([...$parent->getAttributes(), ...array_combine($columns, array_map(self::probe(...), $columns)), ...array_fill_keys($nulled, null)]);

        return $variant;
    }

    /**
     * @param  list<string>  $columns
     */
    private static function comparesNullAsNull(Model $parent, string $method, array $columns, string $column, Builder $probed, int|string $index): bool
    {
        $expected = clone $probed;
        $expected->wheres[$index] = ['type' => 'Null', 'column' => $probed->wheres[$index]['column'], 'boolean' => $probed->wheres[$index]['boolean']];
        $expected->setBindings(array_values(array_filter($probed->getRawBindings()['where'], static fn (mixed $binding): bool => $binding !== self::probe($column))), 'where');
        $nulled = self::probedRelation($parent, $method, $columns, [$column])->getBaseQuery();

        return [$nulled->toSql(), $nulled->getBindings()] === [$expected->toSql(), $expected->getBindings()];
    }

    /**
     * @param  list<string>  $read
     * @return array<string, string>
     */
    private static function membershipPairs(string $name, Model $parent, string $method, array $read): array
    {
        $columns = array_map(static fn (string $column): string => Str::afterLast($column, '.'), $read);
        $base = self::probedRelation($parent, $method, $columns)->getBaseQuery();
        $bindings = $base->getBindings();
        $pairs = [];

        foreach ($columns as $index => $column) {
            $probe = self::probe($column);
            $paired = self::wheresEqualTo($base->wheres, $probe);
            $where = array_key_first($paired);
            $itemColumn = $where === null ? null : $paired[$where]['column'];

            throw_unless(
                $where !== null
                && count($paired) === 1
                && is_string($itemColumn)
                && count(array_keys($bindings, $probe, true)) === 1
                && self::comparesNullAsNull($parent, $method, $columns, $column, $base, $where),
                LogicException::class,
                sprintf(
                    '[%s] filters its members by the parent column [%s] in a form other than [<item column> = %s], so a position query cannot reproduce the filter for each row.',
                    $name,
                    $read[$index],
                    $read[$index],
                ),
            );

            $pairs[$column] = $itemColumn;
        }

        return $pairs;
    }

    /**
     * @param  array<array-key, mixed>  $wheres
     * @return array<array-key, mixed>
     */
    private static function wheresEqualTo(array $wheres, string $probe): array
    {
        return array_filter($wheres, static fn (mixed $where): bool => is_array($where)
            && ($where['type'] ?? null) === 'Basic'
            && ($where['operator'] ?? null) === '='
            && ($where['value'] ?? null) === $probe
            && is_string($where['column'] ?? null)
            && strtolower(is_string($where['boolean'] ?? null) ? $where['boolean'] : 'and') === 'and');
    }

    private static function probe(string $column): string
    {
        return self::PROBE.':'.$column;
    }

    private static function ordersPivots(Model $model): bool
    {
        return array_any(self::orderDeclarations($model), static function (array $declared) use ($model): bool {
            $type = (new ReflectionMethod($model, $declared[0]))->getReturnType();

            return $type instanceof ReflectionNamedType && is_a($type->getName(), BelongsToMany::class, true);
        });
    }

    /**
     * @return list<array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys, columns: list<string>}>
     */
    private static function memberships(Model&Ordered $item, null|string $connection = null): array
    {
        $memberships = [];

        foreach (self::attributes($item, OrderedIn::class) as $orderedIn) {
            $class = $orderedIn->parent;
            $parent = self::asParent(new $class) ?? throw new LogicException(sprintf(
                '[%s] is ordered in [%s], which does not order relations.',
                $item::class,
                $class,
            ));
            $parent->setConnection($connection ?? $item->getConnectionName());

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
     * @param  array<string, mixed>  $attributes
     * @param  list<Model>  $held
     * @param  Closure(array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys, columns: list<string>}): (null|self)  $missing
     */
    private static function resolve(Model&Ordered $item, array $attributes, array $held, Closure $missing): null|self
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

        $loaded = self::storedParent($membership, $attributes);

        return $loaded === null ? $missing($membership) : new self($loaded, $membership['revision']);
    }

    /**
     * @param  array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys, columns: list<string>}  $membership
     * @param  array<string, mixed>  $attributes
     */
    private static function missingParent(array $membership, array $attributes): Model&OrdersRelations&Revisioned
    {
        $parent = $membership['parent']->newInstance([], true);
        $parent->setRawAttributes([...array_fill_keys(self::columnsOf($parent), null), $membership['keys']->ownerKey => $attributes[$membership['keys']->foreignKey]], true);

        return $parent;
    }

    /**
     * @param  array{parent: Model&OrdersRelations&Revisioned, revision: NamesConcurrencyRevision, keys: RelationKeys, columns: list<string>}  $membership
     * @param  array<string, mixed>  $attributes
     */
    private static function storedParent(array $membership, array $attributes): null|(Model&OrdersRelations&Revisioned)
    {
        return self::asParent($membership['parent']->newModelQuery()->where($membership['keys']->ownerKey, $attributes[$membership['keys']->foreignKey])->first());
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
     * @return array<string, array{method: string, declaration: OrdersRelation, relation: HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>}>
     */
    private static function declarations(Model $parent): array
    {
        $declarations = [];
        $relations = [];

        foreach (self::orderDeclarations($parent) as [$method, $declaration]) {
            $relation = self::relation($parent, $method);
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
                $method,
            ));

            self::requireVerifiableMembership($parent, $method, $declaration, $relation);

            $declarations[$declaration->name()] = ['method' => $method, 'declaration' => $declaration, 'relation' => $relation];
            $relations[$item] = $method;
        }

        return $declarations;
    }

    /**
     * @return array{method: string, declaration: OrdersRelation, relation: HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>}
     */
    private static function declaration(Model $parent, NamesConcurrencyRevision $revision): array
    {
        return self::declarations($parent)[(string) $revision->value] ?? throw new LogicException(sprintf(
            '[%s] declares no ordered relation for the [%s] revision.',
            $parent::class,
            $revision->value,
        ));
    }

    /**
     * @param  BelongsToMany<Model, Model>  $relation
     */
    private static function requireOrderedThrough(Model $parent, string $method, BelongsToMany $relation): void
    {
        $related = $relation->getRelated();

        throw_unless(in_array(OrdersThroughPivots::class, class_uses_recursive($related), true), LogicException::class, sprintf(
            '[%s::%s()] orders the pivots of [%s], so [%s] must use [%s].',
            $parent::class,
            $method,
            $related::class,
            $related::class,
            OrdersThroughPivots::class,
        ));

        throw_unless(in_array($parent::class, self::orderedThrough($related), true), LogicException::class, sprintf(
            '[%s::%s()] orders the pivots of [%s], so [%s] must name [%s] in #[%s].',
            $parent::class,
            $method,
            $related::class,
            $related::class,
            $parent::class,
            OrderedThrough::class,
        ));
    }

    /**
     * @internal
     *
     * @param  Model|class-string<Model>  $related
     * @return list<class-string<Model>>
     */
    public static function orderedThrough(Model|string $related): array
    {
        return array_values(array_unique(array_merge([], ...array_map(static fn (OrderedThrough $declared): array => $declared->parents, self::attributes($related, OrderedThrough::class)))));
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public static function watchedRelatedColumns(Model $related, OrdersRelation $declaration): array
    {
        $deletedAt = in_array(SoftDeletes::class, class_uses_recursive($related), true) && method_exists($related, 'getDeletedAtColumn') ? $related->getDeletedAtColumn() : null;

        return [...is_string($deletedAt) ? [$deletedAt] : [], ...$declaration->membershipOf($related->getTable())];
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     */
    private static function requireVerifiableMembership(Model $parent, string $method, OrdersRelation $declaration, HasMany|MorphMany|BelongsToMany $relation): void
    {
        $name = sprintf('%s::%s()', $parent::class, $method);

        if (array_key_exists($name, self::$verifiedMemberships)) {
            return;
        }

        $keys = self::describe($relation);
        $item = $keys->item;
        $related = $relation instanceof BelongsToMany ? $relation->getRelated() : null;
        $models = [];

        foreach ([$item, $parent, ...$related === null ? [] : [$related]] as $model) {
            $models[$model->getTable()] ??= $model;
        }

        $tables = array_keys($models);

        foreach ($declaration->membership as $column) {
            [$table, $named] = array_pad(explode('.', $column, 2), 2, '');

            throw_unless(substr_count($column, '.') === 1 && in_array($table, $tables, true), LogicException::class, sprintf(
                '[%s] declares the membership column [%s], which names no table of the relation. Qualify it by [%s].',
                $name,
                $column,
                implode('], [', $tables),
            ));

            $model = $models[$table];

            throw_if($model->usesTimestamps() && in_array($named, [$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()], true), LogicException::class, sprintf(
                '[%s] declares the membership column [%s], which Eloquent sets on a save of [%s]. Declare a column that only the application writes.',
                $name,
                $column,
                $model::class,
            ));
        }

        $implicit = [
            ...array_map($item->qualifyColumn(...), [...$keys->columns(), OrderedRelations::DELETED_AT]),
            ...$related !== null && method_exists($related, 'getQualifiedDeletedAtColumn') ? [$related->getQualifiedDeletedAtColumn()] : [],
            $parent->qualifyColumn($keys->ownerKey),
        ];
        $read = self::parentColumnsRead($parent, $method);

        foreach ([...self::filteredColumns($name, $relation->getBaseQuery()->wheres, $item->getTable(), $related !== null), ...$read] as $column) {
            throw_unless(in_array($column, [...$implicit, ...$declaration->membership], true), LogicException::class, sprintf(
                '[%s] filters its members by [%s], which its membership does not declare.',
                $name,
                $column,
            ));
        }

        self::requireReproducibleMembership($name, $parent, $method, $relation, $keys, $read);
        self::$verifiedMemberships[$name] = true;
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     * @param  list<string>  $read
     */
    private static function requireReproducibleMembership(string $name, Model $parent, string $method, HasMany|MorphMany|BelongsToMany $relation, RelationKeys $keys, array $read): void
    {
        self::requireOwnJoins($name, $relation);
        self::$membershipPairs[$name] = self::membershipPairs($name, $parent, $method, $read);
        self::requireUncorrelatedSubqueries($name, $parent, $method, $relation, $keys);

        if ($relation instanceof BelongsToMany) {
            self::requireOrderedThrough($parent, $method, $relation);
        }
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     */
    private static function requireOwnJoins(string $name, HasMany|MorphMany|BelongsToMany $relation): void
    {
        $own = $relation instanceof BelongsToMany ? [$relation->getTable()] : [];

        foreach ($relation->getBaseQuery()->joins ?? [] as $join) {
            $table = $join instanceof JoinClause && is_string($join->table) ? $join->table : null;
            $index = $table === null ? false : array_search($table, $own, true);

            throw_if($index === false, LogicException::class, sprintf(
                '[%s] joins [%s] to its members, so a position query cannot reproduce the join for each row.',
                $name,
                $table ?? 'a subquery',
            ));

            unset($own[$index]);
        }
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     */
    private static function requireUncorrelatedSubqueries(string $name, Model $parent, string $method, HasMany|MorphMany|BelongsToMany $relation, RelationKeys $keys): void
    {
        $connection = $relation->getBaseQuery()->getConnection();

        if (! $connection instanceof Connection || $connection->getDriverName() !== 'pgsql' || ! self::filtersBySubquery($relation->getBaseQuery()->wheres)) {
            return;
        }

        $grammar = $connection->getQueryGrammar();
        $probe = sprintf(
            'explain select (%s) from %s',
            self::memberCount($parent, $method, $relation, $keys, self::OUTER, $connection),
            $grammar->wrapTable($keys->item->getTable().' as '.self::OUTER),
        );

        try {
            $connection->transaction(static fn (): array => $connection->select($probe));
        } catch (QueryException $exception) {
            throw new LogicException(sprintf(
                '[%s] filters its members through a subquery that reads a table outside it, so a position query cannot reproduce the filter for each row.',
                $name,
            ), previous: $exception);
        }
    }

    /**
     * @param  array<array-key, mixed>  $wheres
     */
    private static function filtersBySubquery(array $wheres): bool
    {
        $pending = [$wheres];

        while ($pending !== []) {
            $level = array_filter(array_pop($pending), is_array(...));

            if (array_any($level, static fn (array $where): bool => in_array($where['type'] ?? null, ['In', 'NotIn'], true)
                && array_any(is_array($where['values'] ?? null) ? $where['values'] : [], static fn (mixed $value): bool => $value instanceof ExpressionContract))) {
                return true;
            }

            foreach ($level as $where) {
                if (($where['type'] ?? null) === 'Nested' && ($where['query'] ?? null) instanceof Builder) {
                    $pending[] = $where['query']->wheres;
                }
            }
        }

        return false;
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
            self::requireVerifiableClause($name, $where, $nested);
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
     * @param  array<array-key, mixed>  $where
     */
    private static function requireVerifiableClause(string $name, array $where, bool $nested): void
    {
        throw_if(! $nested && str_starts_with(strtolower(is_string($where['boolean'] ?? null) ? $where['boolean'] : 'and'), 'or'), LogicException::class, sprintf(
            '[%s] joins a filter to its members with [or], so the filter reaches the rows of other parents.',
            $name,
        ));

        throw_if(($where['type'] ?? null) === 'Basic' && ($where['value'] ?? null) instanceof ExpressionContract, LogicException::class, sprintf(
            '[%s] compares its members by [%s] to an expression, which a position query cannot re-alias. Compare two columns with whereColumn().',
            $name,
            is_string($where['column'] ?? null) ? $where['column'] : 'an expression',
        ));
    }

    /**
     * @return list<string>
     */
    private static function parentColumnsRead(Model $parent, string $relation): array
    {
        $build = static function (Model $variant) use ($relation): null|array {
            try {
                $query = Relation::noConstraints(static fn (): mixed => $variant->{$relation}());
                assert($query instanceof Relation);
                $base = $query->getQuery()->withoutGlobalScope(SelectsPosition::class)->toBase();

                return [$base->toSql(), $base->getBindings()];
            } catch (Throwable) {
                return null;
            }
        };

        $expected = $build(clone $parent);

        return array_values(array_map(
            $parent->qualifyColumn(...),
            array_filter(self::columnsOf($parent), static fn (string $column): bool => $build(self::probed($parent, [$column])) !== $expected),
        ));
    }

    /**
     * @return list<string>
     */
    private static function columnsOf(Model $parent): array
    {
        $connection = $parent->getConnection();
        $table = $parent->getTable();

        return self::$parentTableColumns[$connection->getName().':'.$table] ??= $connection->getSchemaBuilder()->getColumnListing($table);
    }

    /**
     * @template TAttribute of object
     *
     * @param  Model|class-string<Model>  $model
     * @param  class-string<TAttribute>  $attribute
     * @return list<TAttribute>
     */
    private static function attributes(Model|string $model, string $attribute): array
    {
        $name = is_string($model) ? $model : $model::class;

        if (! array_key_exists($attribute, self::$attributes[$name] ?? [])) {
            $instances = [];

            for ($class = new ReflectionClass($name); $class !== false; $class = $class->getParentClass()) {
                foreach ($class->getAttributes($attribute) as $reflection) {
                    $instances[] = $reflection->newInstance();
                }
            }

            self::$attributes[$name][$attribute] = $instances;
        }

        /** @var list<TAttribute> */
        return self::$attributes[$name][$attribute];
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
            '[%s::%s()] orders [%s], which soft deletes through [%s]. Ordered relations require the soft delete column [%s], because the queries that read and clear rank keys name it.',
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
                '[%s::%s()] uses [%s] as %s. Ordered relations require the key [%s], because the position queries match and order members by it.',
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

    /**
     * @param  Relation<*, *, *>  $relation
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $declared
     */
    private static function sameKeys(Relation $relation, HasMany|MorphMany|BelongsToMany $declared): bool
    {
        if (! $relation instanceof HasMany && ! $relation instanceof MorphMany && ! $relation instanceof BelongsToMany) {
            return false;
        }

        $keys = self::describe($relation);
        $expected = self::describe($declared);

        return $keys->item->getTable() === $expected->item->getTable()
            && $keys->foreignKey === $expected->foreignKey
            && $keys->ownerKey === $expected->ownerKey
            && $keys->morphType === $expected->morphType
            && $keys->morphClass === $expected->morphClass
            && $keys->relatedKey === $expected->relatedKey
            && self::relatedRows($relation) === self::relatedRows($declared);
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     * @return null|array{string, string}
     */
    private static function relatedRows(HasMany|MorphMany|BelongsToMany $relation): null|array
    {
        return $relation instanceof BelongsToMany ? [$relation->getRelated()->getTable(), $relation->getRelatedKeyName()] : null;
    }

    /**
     * @param  BelongsToMany<Model, Model>  $relation
     */
    private static function relatedKeyOf(BelongsToMany $relation, Model $item): mixed
    {
        $key = $relation->getRelatedPivotKeyName();

        if (array_key_exists($key, $item->getAttributes()) || ! $item->exists) {
            return $item->getAttributes()[$key] ?? null;
        }

        $pivot = self::describe($relation)->item;

        return $pivot->newModelQuery()
            ->toBase()
            ->useWritePdo()
            ->where($pivot->getQualifiedKeyName(), $item->getKey())
            ->value($pivot->qualifyColumn($key));
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

    /**
     * @internal
     */
    public static function keyOf(Model $item): int|string
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
        $order = self::lockOrder(array_map(static fn (self $relation): Model => $relation->parent, array_values($relations)));
        usort($relations, static fn (self $first, self $second): int => $order($first->parent) <=> $order($second->parent));

        return $relations;
    }

    /**
     * @param  list<Model>  $parents
     */
    private static function lockInOrder(array $parents): void
    {
        $order = self::lockOrder($parents);
        usort($parents, static fn (Model $first, Model $second): int => $order($first) <=> $order($second));

        foreach ($parents as $parent) {
            self::lockRow($parent);
        }
    }

    /**
     * @param  list<Model>  $models
     * @return Closure(Model): list<mixed>
     */
    private static function lockOrder(array $models): Closure
    {
        $rows = array_unique(array_map(static fn (Model $model): string => $model->getMorphClass()."\0".$model->getKey(), $models));

        if (count($rows) < 2 || ! array_any($models, static fn (Model $model): bool => $model instanceof Ordered || $model instanceof LocksParentsFirst)) {
            return static fn (Model $model): array => [$model->getMorphClass(), $model->getKey()];
        }

        [$ordered] = self::withOrderingParents($models);

        return static fn (Model $model): array => [array_find_key($ordered, static fn (Model $kept): bool => $kept->is($model))];
    }

    /**
     * @param  list<Model>  $locked
     */
    private static function holdsParentsOf(Model $model, array $locked): bool
    {
        return array_all(Provider::parentsReachedBy($model), static fn (Model $parent): bool => array_any($locked, static fn (Model $held): bool => $held->is($parent)));
    }

    private static function lockRow(Model $model): void
    {
        $model->newModelQuery()->whereKey($model->getKey())->toBase()->lock('for no key update')->first([$model->getKeyName()]);
    }

    /**
     * @param  list<Model>  $models
     * @return array{list<Model>, list<null|array<string, mixed>>}
     */
    private static function withOrderingParents(array $models): array
    {
        [$found, $parents, $stored] = self::orderingParentsOf($models);
        $depths = self::depthsBelowRoots($parents);
        $order = array_keys($found);
        usort($order, static fn (int $first, int $second): int => [$depths[$first], $found[$first]->getMorphClass(), $found[$first]->getKey()]
            <=> [$depths[$second], $found[$second]->getMorphClass(), $found[$second]->getKey()]);

        return [
            array_map(static fn (int $index): Model => $found[$index], $order),
            array_map(static fn (int $index): null|array => $stored[$index], $order),
        ];
    }

    /**
     * @param  list<Model>  $models
     * @return array{list<Model>, array<int, list<int>>, array<int, null|array<string, mixed>>}
     */
    private static function orderingParentsOf(array $models): array
    {
        $found = [];
        $parents = [];
        $stored = [];

        $indexOf = static function (Model $model) use (&$found): int {
            $index = array_find_key($found, static fn (Model $kept): bool => $kept->is($model));

            if (is_int($index)) {
                return $index;
            }

            $found[] = $model;

            return count($found) - 1;
        };

        foreach ($models as $model) {
            $indexOf($model);
        }

        for ($index = 0; $index < count($found); $index++) {
            $model = $found[$index];
            $stored[$index] = self::storedMembershipOf($model);
            $held = $model instanceof LocksParentsFirst ? $model->parentsToLockFirst() : [];
            $named = $stored[$index] !== null && $model instanceof Ordered ? self::namedParents($model, $stored[$index], $held) : [];
            $parents[$index] = array_values(array_unique(array_map($indexOf, [...$named, ...Provider::parentsReachedBy($model)])));
            array_map($indexOf, $held);
        }

        return [$found, $parents, $stored];
    }

    /**
     * @param  array<int, list<int>>  $parents
     * @return array<int, int>
     */
    private static function depthsBelowRoots(array $parents): array
    {
        $depths = array_fill_keys(array_keys($parents), 0);
        $previous = [];

        for ($pass = 0; $pass < count($parents) && $depths !== $previous; $pass++) {
            $previous = $depths;

            foreach ($parents as $index => $reached) {
                foreach ($reached as $parent) {
                    $depths[$index] = max($depths[$index], $previous[$parent] + 1);
                }
            }
        }

        return $depths;
    }

    /**
     * @return null|array<string, mixed>
     */
    private static function storedMembershipOf(Model $model): null|array
    {
        if (! $model instanceof Ordered) {
            return null;
        }

        $row = $model->newModelQuery()->whereKey($model->getKey())->toBase()->useWritePdo()->first(self::membershipColumns($model));

        return $row === null ? null : (array) $row;
    }

    /**
     * @return list<string>
     */
    private static function parentMembershipColumns(Model&OrdersRelations&Revisioned $parent): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(static fn (array $declared): array => $declared[1]->membershipOf($parent->getTable()), self::orderDeclarations($parent)),
        )));
    }

    /**
     * @param  list<int|string>  $exclude
     * @param  Closure(): Builder  $rows
     * @return array{null|string, null|string}
     */
    private function bounds(Placement $placement, array $exclude, Closure $rows): array
    {
        $rankKey = $this->keys->item->qualifyColumn(self::RANK_KEY);
        $others = fn (Builder $query): Builder => $query->when(
            $exclude !== [],
            fn (Builder $query): Builder => $query->whereNotIn($this->keys->item->getQualifiedKeyName(), $exclude),
        );

        if ($placement->first) {
            return $this->besideInFlight([null, self::keyOrNull($others($rows())->min($rankKey))], upper: true);
        }

        if ($placement->before !== null) {
            $anchor = $this->anchor($others($this->members()), $placement->before);

            return $this->besideInFlight([self::keyOrNull($others($rows())->where($rankKey, '<', $anchor)->max($rankKey)), $anchor], upper: false);
        }

        if ($placement->after !== null) {
            $anchor = $this->anchor($others($this->members()), $placement->after);

            return $this->besideInFlight([$anchor, self::keyOrNull($others($rows())->where($rankKey, '>', $anchor)->min($rankKey))], upper: true);
        }

        return $this->besideInFlight([self::keyOrNull($others($rows())->max($rankKey)), null], upper: false);
    }

    /**
     * @param  array{null|string, null|string}  $bounds
     * @return array{null|string, null|string}
     */
    private function besideInFlight(array $bounds, bool $upper): array
    {
        foreach (self::$inFlight ?? [] as $item => [$relation, $key]) {
            if ($item->exists === false && $relation->sameOrderAs($this) && self::between($key, ...$bounds)) {
                $bounds[$upper ? 1 : 0] = $key;
            }
        }

        return $bounds;
    }

    private static function between(string $key, null|string $lower, null|string $upper): bool
    {
        return ($lower === null || strcmp($key, $lower) > 0) && ($upper === null || strcmp($key, $upper) < 0);
    }

    /**
     * @param  list<int|string>  $exclude
     * @return array{null|string, null|string}
     */
    private function boundsAfter(Placement $placement, array $exclude, int|string $previous): array
    {
        $rankKey = $this->keys->item->qualifyColumn(self::RANK_KEY);
        [$lower] = $this->bounds($placement, $exclude, $this->indexedRows(...));
        $stored = self::keyOrNull($this->indexedRows()->where($this->keys->item->getQualifiedKeyName(), $previous)->value($rankKey));

        if ($stored !== null && ($lower === null || strcmp($stored, $lower) > 0)) {
            $lower = $stored;
        }

        $upper = $this->indexedRows()
            ->whereNotIn($this->keys->item->getQualifiedKeyName(), $exclude)
            ->when($lower !== null, fn (Builder $query): Builder => $query->where($rankKey, '>', $lower))
            ->min($rankKey);

        return $this->besideInFlight([$lower, self::keyOrNull($upper)], upper: true);
    }

    private function anchor(Builder $members, int|string $anchor): string
    {
        $id = $this->memberKey($anchor);
        $key = $id === null ? null : $members->where($this->keys->item->getQualifiedKeyName(), $id)->value($this->keys->item->qualifyColumn(self::RANK_KEY));

        throw_unless(is_string($key), new InvalidAnchor($this->parent, $this->revision, $anchor));

        return $key;
    }

    private function holdsPlace(int|string $id, Placement $placement): bool
    {
        $key = $this->rows([$id])->value($this->keys->item->qualifyColumn(self::RANK_KEY));
        [$lower, $upper] = $this->bounds($placement, [$id], $this->members(...));

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

        $this->clearRankKeys($ids);

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

            throw_unless($key !== null && array_key_exists($key, $byKey) && ! array_key_exists($key, $order), new MembershipMismatch($this->parent, $this->revision));

            $order[$key] = $byKey[$key];
        }

        throw_unless(count($order) === count($members), new MembershipMismatch($this->parent, $this->revision));

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
        $this->announceRebalance($change->digest);
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
            parent: $this->parent,
            revision: $this->revision,
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
            digest: $this->parent->concurrencyRevision($this->revision),
            source: $source,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(Model $item, array $attributes): void
    {
        self::saveCopy($item, OrderWrite::copy($item, self::timestamps($item, $this->keys->item)), $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function saveCopy(Model $item, null|Model $copy, array $attributes): void
    {
        $copy ??= throw new LogicException(sprintf('[%s:%s] has no row to write.', $item::class, self::keyOf($item)));
        $written = [
            ...OrderWrite::save($copy, $attributes),
            ...array_intersect_key($copy->getAttributes(), [SelectsConcurrencyRevision::ATTRIBUTE => true]),
        ];
        $item->forceFill($written)->syncOriginalAttributes(array_keys($written));
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
        self::liveMembersOf($relation, $relation->getQuery());

        return $relation;
    }

    private function members(): Builder
    {
        $members = $this->scopedRelation()->toBase();
        $members->columns = null;

        return $members->setBindings([], 'select');
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
        $rows = $this->keys->item->newModelQuery()->toBase();

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

    /**
     * @return Collection<int, Model>
     */
    private function currentMembers(): Collection
    {
        $relation = self::relation($this->parent, $this->relationName);
        self::liveMembersOf($relation, $relation->getQuery(), [SelectsPosition::class]);
        $rankKey = $this->keys->item->qualifyColumn(self::RANK_KEY);

        if ($relation instanceof BelongsToMany) {
            $relation->withPivot($this->keys->item->getKeyName());
        }

        /** @var Collection<int, Model> */
        return $relation->reorder()->whereNotNull($rankKey)->orderBy($rankKey)->orderBy($this->keys->item->getQualifiedKeyName())->get();
    }

    /**
     * @param  list<Model>  $models
     */
    private function requireLockable(array $models): void
    {
        $connection = $this->parent->getConnection()->getName();

        foreach ($models as $model) {
            throw_unless($model->exists, LogicException::class, sprintf(
                '[%s] must be saved before it is locked with the members of [%s:%s].',
                $model::class,
                $this->parent->getMorphClass(),
                $this->parent->getKey(),
            ));

            throw_unless($model->getConnection()->getName() === $connection, LogicException::class, sprintf(
                '[%s:%s] on the [%s] connection cannot be locked with the members of [%s:%s] on the [%s] connection.',
                $model::class,
                $model->getKey(),
                $model->getConnection()->getName(),
                $this->parent->getMorphClass(),
                $this->parent->getKey(),
                $connection,
            ));
        }
    }

    /**
     * @template TStored
     * @template TLocked
     *
     * @param  Closure(): TStored  $read
     * @param  Closure(TStored): list<Model>  $alsoLock
     * @param  Closure(TStored, list<Model>): TLocked  $locked
     * @return TLocked
     */
    private function lockWith(Closure $read, Closure $alsoLock, Closure $locked): mixed
    {
        return self::retryWhileMoved($this->parent, function (RuntimeException $moved) use ($read, $alsoLock, $locked): mixed {
            $rows = $read();
            $also = $alsoLock($rows);
            [$models, $stored] = self::withOrderingParents([$this->parent, ...$also]);
            $this->requireLockable($models);

            return $this->parent->getConnection()->transaction(function () use ($read, $locked, $rows, $also, $models, $stored, $moved): mixed {
                foreach ($models as $model) {
                    self::lockRow($model);
                }

                $this->lock();

                throw_unless(
                    [array_map(self::storedMembershipOf(...), $models), $read()] === [$stored, $rows]
                        && array_all($models, static fn (Model $model): bool => self::holdsParentsOf($model, $models)),
                    $moved,
                );

                return $locked($rows, $also);
            });
        });
    }

    /**
     * @param  iterable<mixed>  $items
     * @return list<Model&Ordered>
     */
    private function movable(iterable $items): array
    {
        $movable = [];

        foreach ($items as $item) {
            if (! $item instanceof Model || ! $item instanceof Ordered || $item::class !== $this->keys->item::class) {
                throw new LogicException(sprintf('[%s] is not ordered in [%s].', get_debug_type($item), $this->parent::class));
            }

            throw_unless($item->exists, LogicException::class, sprintf('[%s] must be saved before it moves.', $item::class));
            self::requireConnectionOf($item, $this->parent);

            $key = (string) self::keyOf($item);

            throw_if(array_key_exists($key, $movable), LogicException::class, sprintf('[%s:%s] is named more than once.', $item::class, $key));

            $movable[$key] = $item;
        }

        return array_values($movable);
    }

    /**
     * @param  list<Model&Ordered>  $items
     * @return array<string, array<string, mixed>>
     */
    private function storedRowsOf(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $template = $items[0];
        $keyName = $template->getKeyName();
        $rows = [];

        foreach ($template->newModelQuery()->toBase()->useWritePdo()
            ->whereIn($template->getQualifiedKeyName(), array_map(self::keyOf(...), $items))
            ->orderBy($template->getQualifiedKeyName())
            ->get([$keyName, ...self::membershipColumns($template)]) as $row) {
            $row = (array) $row;
            $key = $row[$keyName] ?? null;
            unset($row[$keyName]);
            $rows[is_int($key) || is_string($key) ? (string) $key : ''] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<Model&Ordered>  $items
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<Model>  $held
     * @return array{array<int, null|self>, list<self>}
     */
    private function sourcesOf(array $items, array $rows, array $held): array
    {
        $sources = [];
        $unique = [];

        foreach ($items as $index => $item) {
            $row = $rows[(string) self::keyOf($item)] ?? null;
            $loaded = array_values(array_filter($item->getRelations(), static fn (mixed $related): bool => $related instanceof Model));
            $source = $row === null ? null : self::sourceOf($item, $row, [$this->parent, ...$held, ...$loaded]);

            if ($source !== null && $source->is($this)) {
                $source = $this;
            } elseif ($source !== null) {
                $slot = array_find_key($unique, static fn (self $other): bool => $other->is($source)) ?? count($unique);
                $unique[$slot] ??= $source;
                $source = $unique[$slot];
            }

            $sources[$index] = $source;
        }

        return [$sources, self::inLockOrder($unique)];
    }

    /**
     * @param  list<Model&Ordered>  $items
     * @param  list<array<string, mixed>>  $original
     * @param  array<int, null|self>  $sources
     */
    private function compareExpected(array $items, array $original, array $sources, string $expected): void
    {
        foreach ($items as $index => $item) {
            $source = $sources[$index] ?? null;
            $read = $source === null ? null : self::sourceOf($item, $original[$index], [$source->parent]);

            if ($source !== null && ($read === null || ! $read->is($source))) {
                throw new StaleConcurrencyRevision(($read ?? $source)->parent, ($read ?? $source)->revision, $expected);
            }
        }

        $this->compareConcurrencyRevision($expected);
    }

    /**
     * @param  list<Model&Ordered>  $items
     * @param  array<int, null|self>  $sources
     * @return array<int, int>
     */
    private function requireMembers(array $items, array $sources): array
    {
        $groups = [];

        foreach ($sources as $index => $source) {
            if ($source !== null) {
                $groups[spl_object_id($source)][] = $index;
            }
        }

        $previous = [];

        foreach ($groups as $indices) {
            $source = $sources[$indices[0]] ?? null;
            $members = array_map(static fn (int $index): Model => $items[$index], $indices);
            $positions = $source === null || $source->unavailable === ParentUnavailability::Missing ? [] : self::positionsOf([$source], $members);

            foreach ($indices as $index) {
                $position = $positions[(string) self::keyOf($items[$index])] ?? null;

                if (is_int($position) || is_string($position)) {
                    $previous[$index] = (int) $position;
                }
            }
        }

        $strangers = array_values(array_map(self::keyOf(...), array_diff_key($items, $previous)));

        if ($strangers !== []) {
            throw new NotAMember($items[0]::class, $strangers);
        }

        return $previous;
    }

    /**
     * @param  list<Model&Ordered>  $items
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<int, null|self>  $sources
     * @param  array<int, int>  $previous
     * @param  list<array<string, mixed>>  $original
     */
    private function moveMembersIn(array $items, Placement $placement, array $rows, array $sources, array $previous, array $original): OrderChange
    {
        $ids = array_map(self::keyOf(...), $items);
        $from = array_map(static fn (null|self $source): self => $source ?? throw new LogicException('Each member has a source.'), $sources);
        $entering = array_filter($from, fn (self $source): bool => $source !== $this);

        if ($items === []) {
            return $this->change([]);
        }

        if ($entering === [] && $this->keptInPlace($items, $ids, $placement, $rows, $previous, $original)) {
            return $this->change([]);
        }

        if ($entering !== []) {
            $this->refuseUnavailable();
        }

        $this->writeKeys($items, $ids, $from, $placement, $rows);

        return $this->changeDestination($items, $ids, $from, $rows, $previous, $original, $this->announceSources($entering, $from, $ids, $previous));
    }

    /**
     * @param  list<Model&Ordered>  $items
     * @param  list<int|string>  $ids
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<int, int>  $previous
     * @param  list<array<string, mixed>>  $original
     */
    private function keptInPlace(array $items, array $ids, Placement $placement, array $rows, array $previous, array $original): bool
    {
        $current = $this->rankKeys($this->rows($ids));

        if (! $this->holdsPlaces($ids, $current, $placement)) {
            return false;
        }

        foreach ($items as $index => $item) {
            $stored = [...($rows[(string) $ids[$index]] ?? []), self::RANK_KEY => $current[$ids[$index]] ?? null];
            $item->forceFill($stored)->syncOriginalAttributes(array_keys($stored));
            $this->settle($item, $previous[$index] ?? null, [$original[$index], $stored]);
        }

        return true;
    }

    /**
     * @param  list<Model&Ordered>  $items
     * @param  list<int|string>  $ids
     * @param  array<int, self>  $from
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function writeKeys(array $items, array $ids, array $from, Placement $placement, array $rows): void
    {
        $keys = $this->keysAt($placement, $ids, count($ids));
        $copies = OrderWrite::copies($this->keys->item, $ids, $this->keys->item->usesTimestamps());
        $this->clearRankKeys(array_values(array_filter(
            $ids,
            fn (int|string $id, int $index): bool => $from[$index] === $this && ($copies[$id] ?? null)?->getAttribute(self::RANK_KEY) !== $keys[$index],
            ARRAY_FILTER_USE_BOTH,
        )));

        foreach ($items as $index => $item) {
            if ($index > 0 && ! self::between($keys[$index], ...$this->boundsAfter($placement, array_slice($ids, $index), $ids[$index - 1]))) {
                $keys = $this->rekeyFrom($index, $keys, $ids, $from, $copies, $placement);
            }

            $stored = $rows[(string) $ids[$index]] ?? [];
            $item->forceFill($stored)->syncOriginalAttributes(array_keys($stored));
            $copy = $copies[$ids[$index]] ?? null;

            if ($copy !== null) {
                $copy->timestamps = self::timestamps($item, $this->keys->item);
            }

            self::saveCopy($item, $copy, $from[$index] === $this
                ? [self::RANK_KEY => $keys[$index]]
                : [...$from[$index]->keys->detached(), ...$this->keys->pointingAt($this->parent), self::RANK_KEY => $keys[$index]]);
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  list<int|string>  $ids
     * @param  array<int, self>  $from
     * @param  array<int|string, Model>  $copies
     * @return list<string>
     */
    private function rekeyFrom(int $index, array $keys, array $ids, array $from, array $copies, Placement $placement): array
    {
        $remaining = array_slice($ids, $index);
        $keys = [...array_slice($keys, 0, $index), ...$this->keysAt($placement, $remaining, count($remaining), $ids[$index - 1])];
        $resident = array_values(array_filter($remaining, fn (int|string $id, int $offset): bool => $from[$index + $offset] === $this, ARRAY_FILTER_USE_BOTH));
        $this->clearRankKeys($resident);

        foreach (array_intersect_key($copies, array_flip($resident)) as $copy) {
            $copy->forceFill([self::RANK_KEY => null])->syncOriginalAttribute(self::RANK_KEY);
        }

        return $keys;
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function clearRankKeys(array $ids): void
    {
        if ($ids !== []) {
            $this->keys->item->newModelQuery()->toBase()->whereIn($this->keys->item->getQualifiedKeyName(), $ids)->update([self::RANK_KEY => null]);
        }
    }

    /**
     * @param  array<int, self>  $entering
     * @param  array<int, self>  $from
     * @param  list<int|string>  $ids
     * @param  array<int, int>  $previous
     * @return list<OrderChange>
     */
    private function announceSources(array $entering, array $from, array $ids, array $previous): array
    {
        $left = [];
        $leaving = [];

        foreach ($entering as $source) {
            $leaving[spl_object_id($source)] = $source;
        }

        foreach (self::inLockOrder($leaving) as $source) {
            $moved = [];

            foreach ($from as $index => $origin) {
                if ($origin === $source) {
                    $moved[$ids[$index]] = $previous[$index] ?? null;
                }
            }

            $left[] = $source->announce($source->change($moved));
        }

        return $left;
    }

    /**
     * @param  list<Model&Ordered>  $items
     * @param  list<int|string>  $ids
     * @param  array<int, self>  $from
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<int, int>  $previous
     * @param  list<array<string, mixed>>  $original
     * @param  list<OrderChange>  $left
     */
    private function changeDestination(array $items, array $ids, array $from, array $rows, array $previous, array $original, array $left): OrderChange
    {
        $order = $this->memberIds();
        $positions = array_flip($order);
        $moved = [];

        foreach ($items as $index => $item) {
            $position = array_key_exists($ids[$index], $positions) ? $positions[$ids[$index]] + 1 : null;
            $before = $from[$index] === $this ? $previous[$index] ?? null : null;

            if ($position !== $before) {
                $moved[$ids[$index]] = $before;
            }

            $this->settle($item, $position, [$original[$index], $rows[(string) $ids[$index]] ?? []]);
        }

        $change = $this->change($moved, count($left) === 1 ? $left[0] : null, $order);

        if ($moved === []) {
            $this->announceRebalance($change->digest);

            return $change;
        }

        return $this->announce($change);
    }

    /**
     * @param  list<array<string, mixed>>  $left
     */
    private function settle(Model&Ordered $item, null|int $position, array $left): void
    {
        $item->forceFill([self::POSITION => $this->unavailable === null ? $position : null])->syncOriginalAttributes([self::POSITION]);
        self::followParent($item, $left, $this->parent);
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array<int|string, string>  $keys
     */
    private function holdsPlaces(array $ids, array $keys, Placement $placement): bool
    {
        [$previous, $upper] = $this->bounds($placement, $ids, $this->members(...));

        foreach ($ids as $id) {
            $key = $keys[$id] ?? null;

            if (! is_string($key) || ($previous !== null && strcmp($key, $previous) <= 0)) {
                return false;
            }

            $previous = $key;
        }

        return $upper === null || ($previous !== null && strcmp($previous, $upper) < 0);
    }

    /**
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relation
     * @param  array<string, mixed>  $record
     */
    private function createClaimed(HasMany|MorphMany|BelongsToMany $relation, array $record): Model
    {
        try {
            if (! $relation instanceof BelongsToMany) {
                $model = $this->claimed = $relation->make($record);
                $model->save();

                return $model;
            }

            $model = $relation->getRelated()->newInstance(array_merge($relation->getQuery()->pendingAttributes, $record));
            $model->save(['touch' => false]);
            $related = $model->getAttribute($relation->getRelatedKeyName());
            $this->claimed = is_int($related) || is_string($related) ? $related : throw new LogicException(sprintf('[%s] has no key.', $model::class));
            $relation->attach($model);

            return $model;
        } finally {
            $this->claimed = null;
        }
    }

    private function batchOf(Model&Ordered $item): null|self
    {
        return array_find(self::$creating, fn (self $batch): bool => $batch->sameOrderAs($this) && $batch->claims($item));
    }

    private function sameOrderAs(self $other): bool
    {
        return $this->is($other) && $this->parent->getConnection()->getName() === $other->parent->getConnection()->getName();
    }

    private function claims(Model&Ordered $item): bool
    {
        return $this->claimed instanceof Model
            ? $this->claimed === $item
            : $this->claimed !== null && $this->keys->relatedKey !== null && self::sameKey($item->getAttribute($this->keys->relatedKey), $this->claimed);
    }

    private function nextPendingKey(): string
    {
        [$last] = $this->bounds(Placement::last(), [], $this->indexedRows(...));
        $next = $this->pendingKeys[0] ?? null;

        if ($next === null || ($last !== null && strcmp($next, $last) <= 0)) {
            $this->pendingKeys = $this->keysAt(Placement::last(), [], max(1, count($this->pendingKeys)));
        }

        return array_shift($this->pendingKeys) ?? throw new LogicException('A create in order ran out of keys.');
    }

    /**
     * @param  list<int|string>  $exclude
     * @return list<string>
     */
    private function keysAt(Placement $placement, array $exclude, int $count, null|int|string $after = null): array
    {
        $bounds = fn (): array => $after === null ? $this->bounds($placement, $exclude, $this->indexedRows(...)) : $this->boundsAfter($placement, $exclude, $after);
        [$lower, $upper] = $bounds();
        $keys = FractionalIndex::keysBetween($lower, $upper, $count);

        if ($keys === [] || max(array_map(strlen(...), $keys)) <= self::MAX_KEY_LENGTH) {
            return $keys;
        }

        $this->rebalance();
        [$lower, $upper] = $bounds();

        return FractionalIndex::keysBetween($lower, $upper, $count);
    }

    private function lock(): void
    {
        /** @var array<string, mixed> $row */
        $row = (array) ($this->parentRow()->lock('for no key update')->first() ?? []);
        $deletedAt = in_array(SoftDeletes::class, class_uses_recursive($this->parent), true) && method_exists($this->parent, 'getDeletedAtColumn') ? $this->parent->getDeletedAtColumn() : null;
        $this->unavailable = match (true) {
            $row === [] => ParentUnavailability::Missing,
            is_string($deletedAt) && ($row[$deletedAt] ?? null) !== null => ParentUnavailability::Trashed,
            default => null,
        };
        $declared = array_diff_key(array_intersect_key($row, array_flip($this->parentColumns)), $this->parent->getDirty());

        if ($declared !== []) {
            $this->parent->setRawAttributes(array_replace($this->parent->getAttributes(), $declared));
            $this->parent->syncOriginalAttributes(array_keys($declared));
        }
    }

    private function parentRow(): Builder
    {
        return $this->parent->newModelQuery()->whereKey($this->parent->getKey())->toBase();
    }
}
