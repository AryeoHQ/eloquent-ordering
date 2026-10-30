<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use LogicException;
use ReflectionClass;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Provides\OrdersThroughPivots;
use Support\Database\Eloquent\Ordering\Schema\Macros;
use WeakMap;

/**
 * @phpstan-type PivotParent array{class-string<Model>, OrdersRelation, non-empty-list<string>}
 * @phpstan-type ReachedParents list<array{OrderedRelation, list<array{Model&Ordered, null|int}>}>
 */
class Provider extends ServiceProvider
{
    /** @var array<class-string<Model>, list<PivotParent>> */
    private static array $parentsByRelated = [];

    public function boot(): void
    {
        (new Macros)->register();

        Builder::macro('withPositions', function (): Builder {
            throw new LogicException(sprintf('[withPositions()] numbers only a query that [%s::query()] returns.', OrderedRelation::class));
        });

        Event::listen(['eloquent.creating: *', 'eloquent.updating: *'], self::requirePreparedOrder(...));

        $this->app->booted(static function (): void {
            self::$parentsByRelated = self::pivotParents();

            foreach (self::$parentsByRelated as $related => $parents) {
                self::raisePivotParentsOf($related, $parents);
            }
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

        foreach (self::$parentsByRelated[$related::class] ?? [] as $parent) {
            foreach (self::pivotsOf($related, $parent) as [$relation]) {
                if (! array_any($parents, static fn (Model $named): bool => $named->is($relation->parent))) {
                    $parents[] = $relation->parent;
                }
            }
        }

        return $parents;
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
     * @return array<class-string<Model>, list<PivotParent>>
     */
    private static function pivotParents(): array
    {
        $reached = [];

        foreach (Arr::wrap(config('concurrency.models')) as $class) {
            if (! is_string($class) || ! is_a($class, Model::class, true) || ! is_a($class, OrdersRelations::class, true)) {
                continue;
            }

            $parent = new $class;

            for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
                foreach ($reflection->getAttributes(OrdersRelation::class) as $attribute) {
                    $declaration = $attribute->newInstance();
                    $relation = method_exists($parent, $declaration->relation) ? $parent->{$declaration->relation}() : null;

                    if (! $relation instanceof BelongsToMany || ($relation instanceof MorphToMany && $relation->getInverse())) {
                        continue;
                    }

                    $related = $relation->getRelated();

                    throw_unless(in_array(OrdersThroughPivots::class, class_uses_recursive($related), true), LogicException::class, sprintf(
                        '[%s::%s()] orders the pivots of [%s], so [%s] must use [%s].',
                        $class,
                        $declaration->relation,
                        $related::class,
                        $related::class,
                        OrdersThroughPivots::class,
                    ));

                    $columns = [...self::softDeleteColumnOf($related), ...$declaration->membershipOf($related->getTable())];

                    if ($columns !== []) {
                        $reached[$related::class][] = [$class, $declaration, $columns];
                    }
                }
            }
        }

        return $reached;
    }

    /**
     * @param  class-string<Model>  $related
     * @param  list<PivotParent>  $parents
     */
    private static function raisePivotParentsOf(string $related, array $parents): void
    {
        /** @var WeakMap<Model, array<int, ReachedParents>> $held */
        $held = new WeakMap;
        $watched = array_values(array_unique(array_merge(...array_column($parents, 2))));

        Event::listen("eloquent.deleting: {$related}", static function (Model $model) use ($parents, $held): void {
            $held[$model] = method_exists($model, 'isForceDeleting') && $model->isForceDeleting() === false ? self::lockPivotParents($model, $parents) : [];
        });

        Event::listen("eloquent.trashed: {$related}", static function (Model $model) use ($parents, $held): void {
            $positions = $held[$model] ?? [];
            unset($held[$model]);

            self::raiseHeldPivotParents($model, $parents, $positions);
        });

        Event::listen("eloquent.updating: {$related}", static function (Model $model) use ($parents, $held, $watched): void {
            $held[$model] = self::lockPivotParents($model, array_filter($parents, static fn (array $parent): bool => $model->isDirty($parent[2])));
            OrderedRelation::preparedRelated($model, $watched);
        });

        Event::listen("eloquent.updated: {$related}", static function (Model $model) use ($parents, $held): void {
            $positions = $held[$model] ?? [];
            unset($held[$model]);
            OrderedRelation::requirePrepared($model);
            $changed = array_filter($parents, static fn (array $parent): bool => $model->wasChanged($parent[2]));
            $unprepared = array_diff_key($changed, $positions);

            throw_if($unprepared !== [], LogicException::class, sprintf(
                '[%s:%s] changed [%s] without a prepared order write. A listener ran after the package\'s [updating] listener, or stopped it.',
                $model::class,
                $model->getKey(),
                implode('], [', array_values(array_intersect(array_keys($model->getChanges()), array_merge(...array_column($unprepared, 2))))),
            ));

            self::raiseHeldPivotParents($model, $changed, $positions);
        });
    }

    /**
     * @param  array<int, PivotParent>  $parents
     * @return array<int, ReachedParents>
     */
    private static function lockPivotParents(Model $related, array $parents): array
    {
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
     * @param  array<int, PivotParent>  $parents
     * @param  array<int, ReachedParents>  $positions
     */
    private static function raiseHeldPivotParents(Model $related, array $parents, array $positions): void
    {
        $reached = [];

        foreach ($parents as $index => $parent) {
            array_push($reached, ...$positions[$index] ?? array_map(static fn (array $reach): array => [$reach[0], []], self::pivotsOf($related, $parent)));
        }

        OrderedRelation::raiseAll(array_map(static fn (array $reach): OrderedRelation => $reach[0], $reached));

        foreach ($reached as [$relation, $pivots]) {
            foreach ($pivots as [$pivot, $previous]) {
                $relation->refiltered($pivot, $previous);
            }
        }
    }

    /**
     * @param  PivotParent  $parent
     * @return list<array{OrderedRelation, list<Model&Ordered>}>
     */
    private static function pivotsOf(Model $related, array $parent): array
    {
        [$class, $declaration] = $parent;
        $template = (new $class)->setConnection($related->getConnectionName());
        $relation = $template->{$declaration->relation}();
        assert($relation instanceof BelongsToMany);

        $pivotClass = $relation->getPivotClass();
        $query = (new $pivotClass)->setTable($relation->getTable())->setConnection($related->getConnectionName())->newQueryWithoutScopes()->useWritePdo()
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

        foreach ($template->newQueryWithoutScopes()->useWritePdo()->whereIn($ownerKey, array_keys($pivots))->orderBy($ownerKey)->get() as $model) {
            if ($model instanceof OrdersRelations && $model instanceof Revisioned) {
                $reached[] = [new OrderedRelation($model, $declaration->revision), array_values(array_filter($pivots[self::keyOf($model->getAttribute($ownerKey))] ?? []))];
            }
        }

        return $reached;
    }

    /**
     * @return list<string>
     */
    private static function softDeleteColumnOf(Model $related): array
    {
        $column = in_array(SoftDeletes::class, class_uses_recursive($related), true) && method_exists($related, 'getDeletedAtColumn') ? $related->getDeletedAtColumn() : null;

        return is_string($column) ? [$column] : [];
    }

    private static function keyOf(mixed $key): string
    {
        return is_int($key) || is_string($key) ? (string) $key : '';
    }
}
