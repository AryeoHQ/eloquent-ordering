<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Scopes;

use Closure;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use stdClass;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use WeakMap;

/**
 * @implements Scope<Model>
 */
final class SelectsPosition implements Scope
{
    public const string PIVOT_ATTRIBUTE = 'pivot_'.OrderedRelation::POSITION;

    private const string UPDATE_BLOCKING_LOCK = '/^for\s+(update|no\s+key\s+update|share)(\s+(nowait|skip\s+locked))?$/i';

    /** @var null|WeakMap<Closure, true> */
    private static null|WeakMap $callbacks = null;

    public function apply(Builder $builder, Model $model): void
    {
        $query = $builder->getQuery();

        if ($model->getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        if ($query->unions !== null) {
            self::withoutPositionsIn($query);

            return;
        }

        $from = self::table($query);

        if ($from === null) {
            return;
        }

        $item = $model instanceof Ordered && self::selectsOnlyTheRow($query->columns, self::qualifier($from)) ? $model : null;
        $pivots = OrderedRelation::orderedPivots($model);

        if ($item === null && $pivots === []) {
            return;
        }

        $callback = self::selectPositions($item, $pivots);
        self::callbacks()[$callback] = true;
        $query->beforeQuery($callback);
    }

    /**
     * @param  array<string, Model&Ordered>  $pivots
     */
    private static function selectPositions(null|(Model&Ordered) $item, array $pivots): Closure
    {
        return static function (QueryBuilder $query) use ($item, $pivots): void {
            if ($query->unions !== null) {
                self::withoutPositionsIn($query);

                return;
            }

            $from = self::table($query);
            $connection = $query->getConnection();

            if ($query->aggregate !== null || $from === null || ! $connection instanceof Connection) {
                return;
            }

            $qualifier = self::qualifier($from);
            $row = $qualifier.'.*';
            $columns = $query->columns === null || $query->columns === ['*'] ? [$row] : $query->columns;
            $own = $item !== null && in_array($row, $columns, true) ? $item : null;
            $positions = [
                ...$own === null ? [] : self::ownPosition($query, $own, $qualifier, $connection),
                ...self::selectedPivotPositions($query, $pivots, $columns, $connection),
            ];

            if ($own !== null || $positions !== []) {
                $query->columns = [...$columns, ...$positions];
            }
        };
    }

    /**
     * @return list<ExpressionContract>
     */
    private static function ownPosition(QueryBuilder $query, Model&Ordered $own, string $qualifier, Connection $connection): array
    {
        if ($query->lock === null) {
            $position = OrderedRelation::positionColumn($own, $qualifier, $connection);

            return $position === null ? [] : [new Expression(sprintf('%s as %s', $position, $query->getGrammar()->wrap(OrderedRelation::POSITION)))];
        }

        if ($connection->transactionLevel() > 0 && self::locksEveryUpdate($query->lock)) {
            self::selectPositionsAfter($query, $own);
        }

        return [];
    }

    /**
     * @param  array<string, Model&Ordered>  $pivots
     * @param  array<array-key, string|ExpressionContract>  $columns
     * @return list<ExpressionContract>
     */
    private static function selectedPivotPositions(QueryBuilder $query, array $pivots, array $columns, Connection $connection): array
    {
        $positions = [];

        foreach (self::pivotPositions($pivots, $query, $connection) as $pivot => $position) {
            if (self::selectsThePivot($columns, $pivot)) {
                $positions[] = new Expression(sprintf('%s as %s', $position, $query->getGrammar()->wrap(self::PIVOT_ATTRIBUTE)));
            }
        }

        return $positions;
    }

    private static function table(QueryBuilder $query): null|string
    {
        return is_string($query->from) && $query->groups === null && $query->havings === null && $query->distinct === false ? $query->from : null;
    }

    /**
     * @param  null|array<array-key, mixed>  $columns
     */
    private static function selectsOnlyTheRow(null|array $columns, string $qualifier): bool
    {
        return $columns === null || $columns === ['*'] || $columns === [$qualifier.'.*'];
    }

    /**
     * @param  array<array-key, string|ExpressionContract>  $columns
     */
    private static function selectsThePivot(array $columns, string $pivot): bool
    {
        return array_any($columns, static fn (mixed $column): bool => is_string($column)
            && preg_match('/^'.preg_quote($pivot, '/').'\.\S+\s+as\s+pivot_\S+$/i', $column) === 1);
    }

    private static function locksEveryUpdate(bool|string $lock): bool
    {
        return is_bool($lock) || preg_match(self::UPDATE_BLOCKING_LOCK, trim($lock)) === 1;
    }

    private static function selectPositionsAfter(QueryBuilder $query, Model&Ordered $item): void
    {
        $query->afterQuery(static function (mixed $rows) use ($item): void {
            $key = $item->getKeyName();
            $read = array_filter($rows instanceof Collection ? $rows->all() : [], static fn (mixed $row): bool => $row instanceof stdClass && property_exists($row, $key));

            if ($read === []) {
                return;
            }

            $stored = [];

            foreach ($item->newModelQuery()->withGlobalScope(self::class, new self)->whereKey(array_values(array_map(static fn (stdClass $row): mixed => $row->{$key}, $read)))->useWritePdo()->toBase()->get() as $row) {
                if ($row instanceof stdClass && property_exists($row, OrderedRelation::POSITION)) {
                    $stored[(string) $row->{$key}] = $row->{OrderedRelation::POSITION};
                }
            }

            if ($stored === []) {
                return;
            }

            foreach ($read as $row) {
                $row->{OrderedRelation::POSITION} = $stored[(string) $row->{$key}] ?? null;
            }
        });
    }

    /**
     * @param  array<string, Model&Ordered>  $ordered
     * @return array<string, string>
     */
    private static function pivotPositions(array $ordered, QueryBuilder $query, Connection $connection): array
    {
        $positions = [];
        $joined = array_filter(array_map(static fn (mixed $join): mixed => $join instanceof JoinClause ? $join->table : null, $query->joins ?? []), is_string(...));

        foreach ($joined as $join) {
            $table = preg_replace('/\s+as\s+\S+$/i', '', $join) ?? $join;
            $pivot = $ordered[$table] ?? null;
            $qualifier = self::qualifier($join);
            $position = $pivot === null ? null : OrderedRelation::positionColumn($pivot, $qualifier, $connection);

            if ($position !== null) {
                $positions[$qualifier] = $position;
            }
        }

        return $positions;
    }

    private static function qualifier(string $from): string
    {
        return preg_match('/\s+as\s+(\S+)$/i', $from, $matches) === 1 ? $matches[1] : $from;
    }

    private static function withoutPositionsIn(QueryBuilder $query): void
    {
        $pending = $query->unions === null ? [] : [$query];

        while ($pending !== []) {
            $parent = array_pop($pending);

            $parent->unions = array_map(static function (array $union) use (&$pending): array {
                $side = $union['query'];
                $nested = null;

                if ($side instanceof Builder) {
                    $side = (clone $side)->withoutGlobalScope(self::class);
                    $nested = $side->getQuery();
                } elseif ($side instanceof QueryBuilder) {
                    $side = clone $side;
                    $side->beforeQueryCallbacks = array_values(array_filter(
                        $side->beforeQueryCallbacks,
                        static fn (mixed $callback): bool => ! ($callback instanceof Closure && self::callbacks()->offsetExists($callback)),
                    ));
                    $nested = $side;
                }

                if ($nested?->unions !== null) {
                    $pending[] = $nested;
                }

                return [...$union, 'query' => $side];
            }, $parent->unions ?? []);
        }
    }

    /**
     * @return WeakMap<Closure, true>
     */
    private static function callbacks(): WeakMap
    {
        return self::$callbacks ??= new WeakMap;
    }
}
