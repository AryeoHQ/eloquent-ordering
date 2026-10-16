<?php

declare(strict_types=1);

namespace Support\Ordering;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Support\Ordering\Contracts\Ordered;
use Support\Ordering\Contracts\OrdersCollection;
use Support\Ordering\Exceptions\InvalidAnchor;
use Support\Ordering\Exceptions\MembershipMismatch;
use Support\Ordering\Exceptions\StaleOrder;
use Support\Ordering\Keys\FractionalIndex;

final class OrderedCollection
{
    public const int MAX_KEY_LENGTH = 64;

    private const string PARKED_KEY_PREFIX = '~';

    /** @var HasMany<Model, Model> */
    private readonly HasMany $relation;

    private readonly Model&Ordered $related;

    public function __construct(
        public readonly Model&OrdersCollection $parent,
        public readonly string $name,
    ) {
        $relation = $parent->{$name}();

        throw_unless(
            $relation instanceof HasMany && $relation->getRelated() instanceof Ordered,
            InvalidArgumentException::class,
            sprintf('[%s::%s()] must return a HasMany relation to an Ordered model.', $parent::class, $name),
        );

        $this->relation = $relation;
        $this->related = $relation->getRelated();
    }

    public static function versionColumn(string $collection): string
    {
        return Str::snake($collection).'_order_version';
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
        return $this->transaction(function () use ($item, $placement, $expectedVersion): OrderChange {
            $version = $this->claim($expectedVersion);
            $id = $this->keyOf($item);
            $previous = $this->positionOf($id);

            if ($previous === null) {
                return $this->join($item, $placement, $version);
            }

            $key = $this->keyAt($placement, $id);
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
            $version = $this->claim($expectedVersion);
            $keys = $this->keys();
            $order = $this->match(array_keys($keys), $ids);

            $this->rewrite($keys, array_combine($order, FractionalIndex::keysBetween(null, null, count($order))));

            $before = array_flip(array_keys($keys));
            $moved = [];

            foreach ($order as $index => $id) {
                if ($before[$id] !== $index) {
                    $moved[$id] = $before[$id] + 1;
                }
            }

            return $this->change($version, $moved);
        });
    }

    /**
     * @internal
     */
    public function place(Model&Ordered $item, Placement $placement): void
    {
        $this->claim($this->lockVersion());

        $item->setAttribute($this->rankKey(), $this->keyAt($placement, null));
    }

    /**
     * @internal
     */
    public function remove(): void
    {
        $this->claim($this->lockVersion());
    }

    private function join(Model&Ordered $item, Placement $placement, int $version): OrderChange
    {
        $id = $this->keyOf($item);
        $source = $item->orderedIn();

        throw_if($source->is($this), InvalidArgumentException::class, sprintf('[%s] is not a member of its own collection.', $id));

        $sourceVersion = $source->claim($source->lockVersion());
        $previous = $source->positionOf($id);

        throw_if($previous === null, InvalidArgumentException::class, sprintf('[%s] is not a member of its own collection.', $id));

        $attributes = [
            $this->relation->getForeignKeyName() => $this->relation->getParentKey(),
            $this->rankKey() => $this->keyAt($placement, null),
        ];

        $item->newQueryWithoutScopes()->whereKey($id)->toBase()->update($attributes);
        $item->forceFill($attributes)->syncOriginalAttributes(array_keys($attributes));
        $item->setRelation($item->orderedParent()->getRelationName(), $this->parent);

        return $this->change($version, [$id => null], $source->change($sourceVersion, [$id => $previous]));
    }

    private function claim(int $expectedVersion): int
    {
        $column = self::versionColumn($this->name);

        $claimed = $this->parentRow()->where($column, $expectedVersion)->increment($column);

        throw_if($claimed === 0, new StaleOrder($this, $expectedVersion));

        return $expectedVersion + 1;
    }

    private function lockVersion(): int
    {
        return (int) $this->parentRow()->lockForUpdate()->value(self::versionColumn($this->name));
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
        $key = $members->where($this->related->getKeyName(), $anchor)->value($this->rankKey());

        throw_unless(is_string($key), new InvalidAnchor($this, $anchor));

        return $key;
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
            $this->member($id)->update([$this->rankKey() => self::PARKED_KEY_PREFIX.$key]);
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

    private function is(self $other): bool
    {
        return $this->name === $other->name && $this->parent->is($other->parent);
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
