<?php

declare(strict_types=1);

namespace Support\Ordering;

use Closure;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use ReflectionNamedType;
use Support\Ordering\Contracts\Ordered;
use Support\Ordering\Contracts\OrdersCollection;
use Support\Ordering\Exceptions\InvalidAnchor;
use Support\Ordering\Exceptions\MembershipMismatch;
use Support\Ordering\Exceptions\StaleOrder;
use Support\Ordering\Keys\FractionalIndex;

final class OrderedCollection
{
    public const int MAX_KEY_LENGTH = 64;

    private const string PREFIX_OUTSIDE_KEY_ALPHABET = '~';

    /** @var HasMany<Model, Model> */
    private readonly HasMany $relation;

    private readonly Model&Ordered $related;

    public function __construct(
        public readonly Model&OrdersCollection $parent,
        public readonly string $name,
    ) {
        $message = sprintf('[%s::%s()] must return a HasMany relation to an Ordered model.', $parent::class, $name);

        throw_unless(self::declaresHasMany($parent, $name), InvalidArgumentException::class, $message);

        $relation = $parent->{$name}();

        throw_unless($relation instanceof HasMany && $relation->getRelated() instanceof Ordered, InvalidArgumentException::class, $message);

        $this->relation = $relation;
        $this->related = $relation->getRelated();
    }

    public static function versionColumn(string $collection): string
    {
        return Str::snake($collection).'_order_version';
    }

    /**
     * @internal
     */
    public static function place(Model&Ordered $item, Placement $placement): void
    {
        $collection = self::of($item, $item->getAttribute($item->orderedParent()->getForeignKeyName()))
            ?? throw new LogicException(sprintf('[%s:%s] has no parent that orders it.', $item::class, $item->getKey()));

        $collection->claimCurrent();

        $item->setAttribute($collection->rankKey(), $collection->keyAt($placement, null));
    }

    /**
     * @internal
     */
    public static function remove(Model&Ordered $item): void
    {
        $parentKey = self::storedParentKey($item);
        $collection = self::of($item, $parentKey) ?? throw StaleOrder::member($item);

        $collection->claimCurrent();

        throw_unless(self::sameKey(self::storedParentKey($item), $parentKey), StaleOrder::member($item));
    }

    public function version(): int
    {
        return (int) $this->parentRow()->value(self::versionColumn($this->name));
    }

    public function tag(): string
    {
        return $this->tagAt($this->version());
    }

    public function move(Model&Ordered $item, Placement $placement, int $expectedVersion): OrderChange
    {
        throw_unless($item instanceof $this->related, InvalidArgumentException::class, sprintf('The [%s] order cannot hold [%s].', $this->name, $item::class));

        return $this->transaction(function () use ($item, $placement, $expectedVersion): OrderChange {
            $id = $this->keyOf($item);
            $parentKey = $item->getAttribute($item->orderedParent()->getForeignKeyName());
            $source = self::sameKey($parentKey, $this->relation->getParentKey()) ? $this : (self::of($item, $parentKey) ?? throw StaleOrder::member($item));

            $this->lockWith($source);

            throw_unless(self::sameKey(self::storedParentKey($item), $parentKey), StaleOrder::member($item));

            $this->lockAt($expectedVersion);

            if ($source !== $this) {
                return $this->join($item, $source, $placement, $expectedVersion);
            }

            $previous = $this->positionOf($id);

            if ($this->holdsPlace($id, $placement)) {
                return $this->change($expectedVersion, []);
            }

            $key = $this->keyAt($placement, $id);
            $version = $this->claim($expectedVersion);

            $this->member($id)->update([$this->rankKey() => $key]);
            $item->setAttribute($this->rankKey(), $key)->syncOriginalAttribute($this->rankKey());

            return $this->change($version, [$id => $previous]);
        });
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function reorder(array $ids, int $expectedVersion): OrderChange
    {
        return $this->transaction(function () use ($ids, $expectedVersion): OrderChange {
            $this->lockAt($expectedVersion);

            $keys = $this->keys();
            $members = array_keys($keys);
            $order = $this->match($members, $ids);

            if ($order === $members) {
                return $this->change($expectedVersion, []);
            }

            $version = $this->claim($expectedVersion);

            $this->rewrite($keys, array_combine($order, FractionalIndex::keysBetween(null, null, count($order))));

            $before = array_flip($members);
            $moved = [];

            foreach ($order as $index => $id) {
                if ($before[$id] !== $index) {
                    $moved[$id] = $before[$id] + 1;
                }
            }

            return $this->change($version, $moved);
        });
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

    private static function of(Model&Ordered $item, mixed $parentKey): self|null
    {
        $relation = $item->orderedParent();
        $parent = $parentKey === null ? null : $relation->getRelated()->newQueryWithoutScopes()->where($relation->getOwnerKeyName(), $parentKey)->first();

        return $parent instanceof OrdersCollection ? $parent->orderedCollection($item->orderedCollectionName()) : null;
    }

    private static function storedParentKey(Model&Ordered $item): mixed
    {
        return $item->newQueryWithoutScopes()->whereKey($item->getKey())->toBase()->value($item->orderedParent()->getForeignKeyName());
    }

    private static function sameKey(mixed $first, mixed $second): bool
    {
        return (is_int($first) || is_string($first))
            && (is_int($second) || is_string($second))
            && (string) $first === (string) $second;
    }

    private function join(Model&Ordered $item, self $source, Placement $placement, int $expectedVersion): OrderChange
    {
        $id = $this->keyOf($item);
        $previous = $source->positionOf($id);

        $attributes = [
            $this->relation->getForeignKeyName() => $this->relation->getParentKey(),
            $this->rankKey() => $this->keyAt($placement, null),
        ];

        $version = $this->claim($expectedVersion);
        $sourceVersion = $source->claim($source->version());

        $item->newQueryWithoutScopes()->whereKey($id)->toBase()->update($attributes);
        $item->forceFill($attributes)->syncOriginalAttributes(array_keys($attributes));
        $item->setRelation($item->orderedParent()->getRelationName(), $this->parent);

        return $this->change($version, [$id => null], $source->change($sourceVersion, [$id => $previous]));
    }

    private function lockWith(self $source): void
    {
        $this->parent->newQueryWithoutScopes()
            ->whereKey(array_values(array_unique([$this->parent->getKey(), $source->parent->getKey()])))
            ->orderBy($this->parent->getKeyName())
            ->lockForUpdate()
            ->toBase()
            ->pluck($this->parent->getKeyName());
    }

    private function lockAt(int $expectedVersion): void
    {
        throw_unless($this->lockVersion() === $expectedVersion, StaleOrder::version($this, $expectedVersion));
    }

    private function claimCurrent(): void
    {
        // A create or delete has no expected version, so wait for the row rather than fail as stale.
        $this->claim($this->lockVersion());
    }

    private function claim(int $expectedVersion): int
    {
        $column = self::versionColumn($this->name);

        // Compare again: SQLite takes no row lock, so this statement is its only guard.
        $claimed = $this->parentRow()->where($column, $expectedVersion)->increment($column);

        throw_if($claimed === 0, StaleOrder::version($this, $expectedVersion));

        return $expectedVersion + 1;
    }

    private function lockVersion(): int
    {
        return (int) $this->parentRow()->lockForUpdate()->value(self::versionColumn($this->name));
    }

    private function holdsPlace(int|string $id, Placement $placement): bool
    {
        $key = $this->member($id)->value($this->rankKey());
        [$lower, $upper] = $this->bounds($placement, $id);

        return is_string($key)
            && ($lower === null || strcmp($key, $lower) > 0)
            && ($upper === null || strcmp($key, $upper) < 0);
    }

    private function keyAt(Placement $placement, int|string|null $exclude): string
    {
        $key = FractionalIndex::keyBetween(...$this->bounds($placement, $exclude));

        if (strlen($key) <= self::MAX_KEY_LENGTH) {
            return $key;
        }

        $this->rebalance();

        return FractionalIndex::keyBetween(...$this->bounds($placement, $exclude));
    }

    /**
     * @return array{string|null, string|null}
     */
    private function bounds(Placement $placement, int|string|null $exclude): array
    {
        $rankKey = $this->rankKey();
        $others = fn (): Builder => $this->members()->when(
            $exclude !== null,
            fn (Builder $query): Builder => $query->where($this->related->getKeyName(), '!=', $exclude),
        );

        if ($placement->first) {
            return [null, $this->rankKeyOrNull($others()->min($rankKey))];
        }

        if ($placement->before !== null) {
            $anchor = $this->anchor($others(), $placement->before);

            return [$this->rankKeyOrNull($others()->where($rankKey, '<', $anchor)->max($rankKey)), $anchor];
        }

        if ($placement->after !== null) {
            $anchor = $this->anchor($others(), $placement->after);

            return [$anchor, $this->rankKeyOrNull($others()->where($rankKey, '>', $anchor)->min($rankKey))];
        }

        return [$this->rankKeyOrNull($others()->max($rankKey)), null];
    }

    private function anchor(Builder $members, int|string $anchor): string
    {
        $id = $this->anchorId($anchor);
        $key = $id === null ? null : $members->where($this->related->getKeyName(), $id)->value($this->rankKey());

        throw_unless(is_string($key), new InvalidAnchor($this, $anchor));

        return $key;
    }

    private function anchorId(int|string $anchor): int|string|null
    {
        if (in_array($this->related->getKeyType(), ['int', 'integer'], true)) {
            return is_int($anchor) || (string) (int) $anchor === $anchor ? (int) $anchor : null;
        }

        if (in_array(HasUuids::class, class_uses_recursive($this->related), true)) {
            return Str::isUuid((string) $anchor) ? (string) $anchor : null;
        }

        return (string) $anchor;
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
        $changed = array_filter($next, fn (string $key, int|string $id): bool => $current[$id] !== $key, ARRAY_FILTER_USE_BOTH);

        foreach ($changed as $id => $key) {
            $this->member($id)->update([$this->rankKey() => self::PREFIX_OUTSIDE_KEY_ALPHABET.$key]);
        }

        foreach ($changed as $id => $key) {
            $this->member($id)->update([$this->rankKey() => $key]);
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
     * @param  array<int|string, int|null>  $moved
     */
    private function change(int $version, array $moved, OrderChange|null $source = null): OrderChange
    {
        $ids = $this->ids();
        $positions = array_flip($ids);

        return new OrderChange(
            collection: $this,
            order: array_map(
                fn (int|string $id): array => ['id' => $id, 'position' => $positions[$id] + 1],
                $ids,
            ),
            changed: array_map(
                fn (int|string $id, int|null $previous): array => [
                    'id' => $id,
                    'position' => array_key_exists($id, $positions) ? $positions[$id] + 1 : null,
                    'previous_position' => $previous,
                ],
                array_keys($moved),
                array_values($moved),
            ),
            version: $version,
            tag: $this->tagAt($version),
            source: $source,
        );
    }

    private function positionOf(int|string $id): int|null
    {
        $key = $this->member($id)->value($this->rankKey());

        if (! is_string($key)) {
            return null;
        }

        return $this->members()->where($this->rankKey(), '<', $key)->count() + 1;
    }

    /**
     * @return array<int|string, string>
     */
    private function keys(): array
    {
        /** @var array<int|string, string> */
        return $this->members()->orderBy($this->rankKey())->pluck($this->rankKey(), $this->related->getKeyName())->all();
    }

    /**
     * @return list<int|string>
     */
    private function ids(): array
    {
        /** @var list<int|string> */
        return $this->members()->orderBy($this->rankKey())->pluck($this->related->getKeyName())->all();
    }

    private function members(): Builder
    {
        return $this->related->newQueryWithoutScopes()
            ->where($this->relation->getForeignKeyName(), $this->relation->getParentKey())
            ->toBase();
    }

    private function member(int|string $id): Builder
    {
        return $this->members()->where($this->related->getKeyName(), $id);
    }

    private function parentRow(): Builder
    {
        return $this->parent->newQueryWithoutScopes()->whereKey($this->parent->getKey())->toBase();
    }

    private function rankKey(): string
    {
        return $this->related->getRankKeyName();
    }

    private function rankKeyOrNull(mixed $key): string|null
    {
        return is_string($key) ? $key : null;
    }

    private function keyOf(Model $item): int|string
    {
        $key = $item->getKey();

        throw_unless(is_int($key) || is_string($key), InvalidArgumentException::class, sprintf('[%s] has no key.', $item::class));

        return $key;
    }

    private function tagAt(int $version): string
    {
        $basis = json_encode([$this->parent->getMorphClass(), $this->parent->getKey(), $this->name, $version], JSON_THROW_ON_ERROR);

        return '"'.hash('xxh128', $basis).'"';
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function transaction(Closure $callback): mixed
    {
        return $this->parent->getConnection()->transaction($callback);
    }
}
