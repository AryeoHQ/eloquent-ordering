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

        $callback = static function (QueryBuilder $query) use ($item, $pivots): void {
            if ($query->unions !== null) {
                self::withoutPositionsIn($query);

                return;
            }

            $from = self::table($query);
            $connection = $query->getConnection();

            if ($query->aggregate !== null || $from === null || ! $connection instanceof Connection) {
                return;
            }

            $grammar = $query->getGrammar();
            $qualifier = self::qualifier($from);
            $row = $qualifier.'.*';
            $columns = $query->columns === null || $query->columns === ['*'] ? [$row] : $query->columns;
            $positions = [];

            $own = $item !== null && in_array($row, $columns, true) ? $item : null;

            if ($own !== null && $query->lock !== null) {
                self::selectPositionsAfter($query, $own);
            } elseif ($own !== null) {
                $position = OrderedRelation::positionColumn($own, $qualifier, $connection);

                if ($position !== null) {
                    $positions[] = new Expression(sprintf('%s as %s', $position, $grammar->wrap(OrderedRelation::POSITION)));
                }
            }

            foreach (self::pivotPositions($pivots, $query, $connection) as $pivot => $position) {
                if (self::selectsThePivot($columns, $pivot)) {
                    $positions[] = new Expression(sprintf('%s as %s', $position, $grammar->wrap(self::PIVOT_ATTRIBUTE)));
                }
            }

            if ($positions !== []) {
                $query->columns = [...$columns, ...$positions];
            }
        };

        self::callbacks()[$callback] = true;
        $query->beforeQuery($callback);
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

        foreach ($query->joins ?? [] as $join) {
            if (! $join instanceof JoinClause || ! is_string($join->table)) {
                continue;
            }

            $table = preg_replace('/\s+as\s+\S+$/i', '', $join->table) ?? $join->table;
            $pivot = $ordered[$table] ?? null;
            $qualifier = self::qualifier($join->table);
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
        $pending = [$query];

        while ($pending !== []) {
            $parent = array_pop($pending);

            if ($parent->unions === null) {
                continue;
            }

            $parent->unions = array_map(static function (array $union) use (&$pending): array {
                $side = $union['query'];

                if ($side instanceof Builder) {
                    $side = (clone $side)->withoutGlobalScope(self::class);
                    $pending[] = $side->getQuery();
                } elseif ($side instanceof QueryBuilder) {
                    $side = clone $side;
                    $side->beforeQueryCallbacks = array_values(array_filter(
                        $side->beforeQueryCallbacks,
                        static fn (mixed $callback): bool => ! ($callback instanceof Closure && self::callbacks()->offsetExists($callback)),
                    ));
                    $pending[] = $side;
                }

                return [...$union, 'query' => $side];
            }, $parent->unions);
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
