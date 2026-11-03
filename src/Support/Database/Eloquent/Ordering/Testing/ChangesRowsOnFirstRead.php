<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Support\Database\Eloquent\Ordering\OrderedRelation;

trait ChangesRowsOnFirstRead
{
    protected function deleteOnFirstRead(Model $model): void
    {
        if (! method_exists($model, 'getDeletedAtColumn')) {
            $this->changeOnFirstRead($model, static fn (Model $model): mixed => $model->newQueryWithoutScopes()->whereKey($model->getKey())->toBase()->delete());

            return;
        }

        $attributes = [$model->getDeletedAtColumn() => $model->freshTimestampString(), OrderedRelation::RANK_KEY => null];

        $this->changeOnFirstRead($model, static fn (Model $model): mixed => $model->newQueryWithoutScopes()->whereKey($model->getKey())->toBase()->update($attributes));
    }

    /**
     * @template TRelated of Model
     * @template TChild of Model
     *
     * @param  BelongsTo<TRelated, TChild>  $parent
     */
    protected function moveOnFirstRead(BelongsTo $parent, Model $target): void
    {
        $attributes = [$parent->getForeignKeyName() => $target->getAttribute($parent->getOwnerKeyName())];

        $this->changeOnFirstRead($parent->getChild(), static fn (Model $model): mixed => $model->newQueryWithoutScopes()->whereKey($model->getKey())->toBase()->update($attributes));
    }

    /**
     * @param  callable(Model): mixed  $change
     */
    private function changeOnFirstRead(Model $model, callable $change): void
    {
        $connection = $model->getConnection();
        $from = 'from '.$connection->getQueryGrammar()->wrapTable($model->getTable());
        $changed = false;

        DB::listen(static function (QueryExecuted $query) use ($model, $change, $connection, $from, &$changed): void {
            if ($changed || $query->connectionName !== $connection->getName() || ! str_starts_with($query->sql, 'select ') || ! str_contains($query->sql, $from)) {
                return;
            }

            $changed = true;
            $change($model);
        });
    }
}
