<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\OrderChange;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Provides\OrdersThroughPivots;
use Support\Database\Eloquent\Ordering\Schema\Macros;
use Support\Database\Eloquent\Ordering\Scopes\SelectsPosition;
use WeakMap;

/**
 * @phpstan-type PivotParent array{class-string<Model>, string, OrdersRelation, non-empty-list<string>}
 * @phpstan-type ReachedParents list<array{OrderedRelation, list<array{Model&Ordered, null|int}>}>
 */
class Provider extends ServiceProvider
{
    /** @var array<class-string<Model>, list<PivotParent>> */
    private static array $parentsByRelated = [];

    public function boot(): void
    {
        (new Macros)->register();

        Builder::macro('withoutPositions', function (): Builder {
            return $this->withoutGlobalScope(SelectsPosition::class);
        });

        Relation::macro('arrange', /** @param list<int|string> $ids */ function (array $ids, null|string $expected = null): OrderChange {
            return OrderedRelation::ordering($this)->arrange($ids, $expected);
        });

        Relation::macro('lockedMembers', /** @return Collection<int, Model> */ function (Model ...$alsoLock): Collection {
            return OrderedRelation::ordering($this)->lockedMembers(array_values($alsoLock));
        });

        Event::listen(['eloquent.creating: *', 'eloquent.updating: *'], self::requirePreparedOrder(...));
    }

    /**
     * @internal
     *
     * @param  class-string<Model>  $related
     */
    public static function listenToPivotParentsOf(string $related): void
    {
        /** @var WeakMap<Model, array<int, ReachedParents>> $held */
        $held = new WeakMap;

        Event::listen("eloquent.deleting: {$related}", static function (Model $model) use ($held): void {
            $held[$model] = self::trashes($model) ? self::lockPivotParents($model, self::pivotParentsOf($model)) : [];
        });

        Event::listen("eloquent.trashed: {$related}", static function (Model $model) use ($held): void {
            $positions = $held[$model] ?? [];
            unset($held[$model]);
            self::reportTrashedPivotParents($model, $positions);
        });

        Event::listen("eloquent.updating: {$related}", static function (Model $model) use ($held): void {
            $parents = self::pivotParentsOf($model);

            $held[$model] = self::lockPivotParents($model, array_filter($parents, static fn (array $parent): bool => $model->isDirty($parent[3])));
            OrderedRelation::preparedRelated($model, array_values(array_unique(array_merge(...array_column($parents, 3)))));
        });

        Event::listen("eloquent.updated: {$related}", static function (Model $model) use ($held): void {
            $positions = $held[$model] ?? [];
            unset($held[$model]);
            self::reportUpdatedPivotParents($model, $positions);
        });
    }

    /**
     * @internal
     *
     * @return list<Model>
     */
    public static function parentsReachedBy(Model $related): array
    {
        $parents = [];

        foreach (self::pivotParentsOf($related) as $parent) {
            foreach (self::pivotsOf($related, $parent) as [$relation]) {
                if (! array_any($parents, static fn (Model $named): bool => $named->is($relation->parent))) {
                    $parents[] = $relation->parent;
                }
            }
        }

        return $parents;
    }

    /**
     * @internal
     */
    public static function locksPivotParentsOf(Model $related, bool $deleting): bool
    {
        $parents = self::pivotParentsOf($related);

        return $deleting
            ? $parents !== [] && self::trashes($related)
            : array_any($parents, static fn (array $parent): bool => $related->isDirty($parent[3]));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function requirePreparedOrder(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;

        if ($model instanceof Model) {
            OrderedRelation::requirePrepared($model);
        }
    }

    /**
     * @param  array<int, ReachedParents>  $positions
     */
    private static function reportTrashedPivotParents(Model $model, array $positions): void
    {
        OrderedRelation::forgetParentsLockedFirst($model);

        self::reportHeldPivotParents($positions);
    }

    /**
     * @param  array<int, ReachedParents>  $positions
     */
    private static function reportUpdatedPivotParents(Model $model, array $positions): void
    {
        OrderedRelation::forgetParentsLockedFirst($model);
        OrderedRelation::requirePrepared($model);
        $changed = array_filter(self::pivotParentsOf($model), static fn (array $parent): bool => $model->wasChanged($parent[3]));
        $unprepared = array_diff_key($changed, $positions);

        throw_if($unprepared !== [], LogicException::class, sprintf(
            '[%s:%s] changed [%s] without a prepared order write. A listener ran after the package\'s [updating] listener, or stopped it.',
            $model::class,
            $model->getKey(),
            implode('], [', array_values(array_intersect(array_keys($model->getChanges()), array_merge(...array_column($unprepared, 3))))),
        ));

        self::reportHeldPivotParents(array_intersect_key($positions, $changed));
    }

    /**
     * @return list<PivotParent>
     */
    private static function pivotParentsOf(Model $related): array
    {
        return self::$parentsByRelated[$related::class] ??= self::pivotParents($related);
    }

    /**
     * @return list<PivotParent>
     */
    private static function pivotParents(Model $related): array
    {
        if (! in_array(OrdersThroughPivots::class, class_uses_recursive($related), true)) {
            return [];
        }

        $reached = [];

        foreach (OrderedRelation::orderedThrough($related) as $class) {
            if (! is_a($class, Model::class, true) || ! is_a($class, OrdersRelations::class, true)) {
                continue;
            }

            $parent = new $class;

            foreach (OrderedRelation::orderDeclarations($parent) as [$method, $declaration]) {
                $relation = method_exists($parent, $method) ? $parent->{$method}() : null;

                if ($relation instanceof BelongsToMany && ! ($relation instanceof MorphToMany && $relation->getInverse()) && $relation->getRelated()::class === $related::class) {
                    $columns = OrderedRelation::watchedRelatedColumns($related, $declaration);

                    if ($columns !== []) {
                        $reached[] = [$class, $method, $declaration, $columns];
                    }
                }
            }
        }

        return $reached;
    }

    /**
     * @param  array<int, PivotParent>  $parents
     * @return array<int, ReachedParents>
     */
    private static function lockPivotParents(Model $related, array $parents): array
    {
        if ($parents !== [] && $related instanceof Ordered) {
            OrderedRelation::lockParentsFirstUnlessLocked($related);
        }

        $reached = array_map(static fn (array $parent): array => self::pivotsOf($related, $parent), $parents);

        OrderedRelation::lockAll(array_merge([], ...array_map(
            static fn (array $pivots): array => array_map(static fn (array $reach): OrderedRelation => $reach[0], $pivots),
            array_values($reached),
        )));

        return array_map(static fn (array $pivots): array => array_map(
            static fn (array $reach): array => [$reach[0], array_map(static fn (Model&Ordered $pivot): array => [$pivot, $reach[0]->memberPosition($pivot)], $reach[1])],
            $pivots,
        ), $reached);
    }

    /**
     * @param  array<int, ReachedParents>  $positions
     */
    private static function reportHeldPivotParents(array $positions): void
    {
        foreach ($positions as $reached) {
            foreach ($reached as [$relation, $pivots]) {
                foreach ($pivots as [$pivot, $previous]) {
                    $relation->refiltered($pivot, $previous);
                }
            }
        }
    }

    /**
     * @param  PivotParent  $parent
     * @return list<array{OrderedRelation, list<Model&Ordered>}>
     */
    private static function pivotsOf(Model $related, array $parent): array
    {
        [$class, $method, $declaration] = $parent;
        $template = (new $class)->setConnection($related->getConnectionName());
        $relation = $template->{$method}();
        assert($relation instanceof BelongsToMany);

        $pivotClass = $relation->getPivotClass();
        $query = (new $pivotClass)->setTable($relation->getTable())->setConnection($related->getConnectionName())->newQueryWithoutScopes()->withoutGlobalScope(SelectsPosition::class)->useWritePdo()
            ->where($relation->getRelatedPivotKeyName(), $related->getAttribute($relation->getRelatedKeyName()));

        if ($relation instanceof MorphToMany) {
            $query->where($relation->getMorphType(), $relation->getMorphClass());
        }

        $pivots = [];

        foreach ($query->get() as $pivot) {
            $positioned = $pivot instanceof Ordered && $pivot->getAttribute(OrderedRelation::RANK_KEY) !== null;
            $pivots[self::keyOf($pivot->getAttribute($relation->getForeignPivotKeyName()))][] = $positioned ? $pivot : null;
        }

        if ($pivots === []) {
            return [];
        }

        $reached = [];
        $ownerKey = $relation->getParentKeyName();

        foreach ($template->newQueryWithoutScopes()->withoutGlobalScope(SelectsPosition::class)->useWritePdo()->whereIn($ownerKey, array_keys($pivots))->orderBy($ownerKey)->get() as $model) {
            if ($model instanceof OrdersRelations && $model instanceof Revisioned) {
                $reached[] = [new OrderedRelation($model, $declaration->revision), array_values(array_filter($pivots[self::keyOf($model->getAttribute($ownerKey))] ?? []))];
            }
        }

        return $reached;
    }

    private static function trashes(Model $related): bool
    {
        return method_exists($related, 'isForceDeleting') && $related->isForceDeleting() === false;
    }

    private static function keyOf(mixed $key): string
    {
        return is_int($key) || is_string($key) ? (string) $key : '';
    }
}
