<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Support\Database\Eloquent\Ordering\OrderedRelation;

trait ChangesRowsOnFirstRead
{
    private bool $forgingOnFirstRead = false;

    protected function deleteOnFirstRead(Model $model): void
    {
        if (! method_exists($model, 'getDeletedAtColumn')) {
            $this->changeOnFirstRead($model, static fn (Builder $row): mixed => $row->delete());

            return;
        }

        $attributes = [$model->getDeletedAtColumn() => $model->freshTimestampString()];

        if ($model instanceof Ordered) {
            $attributes[OrderedRelation::RANK_KEY] = null;
        }

        $this->changeOnFirstRead($model, static fn (Builder $row): mixed => $row->update($attributes));
    }

    /**
     * @template TRelated of Model
     * @template TChild of Model
     *
     * @param  BelongsTo<TRelated, TChild>  $parent
     */
    protected function moveOnFirstRead(BelongsTo $parent, Model $target): void
    {
        $child = $parent->getChild();
        $owner = $target->getAttribute($parent->getOwnerKeyName() ?? $target->getKeyName());

        if ($owner === null) {
            throw new LogicException(sprintf('[%s] has no owner key, so a forged move cannot point a row at it.', $target::class));
        }

        $membership = [$parent->getForeignKeyName() => $owner];

        if ($parent instanceof MorphTo) {
            $membership[$parent->getMorphType()] = $target->getMorphClass();
        }

        $this->changeOnFirstRead($child, static function (Builder $row) use ($child, $membership): mixed {
            if (! $child instanceof Ordered) {
                return $row->update($membership);
            }

            $members = $child->newQueryWithoutScopes()->where($membership);

            if (method_exists($child, 'getDeletedAtColumn')) {
                $members->whereNull($child->getDeletedAtColumn());
            }

            $last = $members->max(OrderedRelation::RANK_KEY);

            return $row->update([...$membership, OrderedRelation::RANK_KEY => FractionalIndex::keyBetween(is_string($last) ? $last : null, null)]);
        });
    }

    /**
     * @param  callable(Builder): mixed  $change
     */
    private function changeOnFirstRead(Model $model, callable $change): void
    {
        $key = $model->getKey();

        if ($key === null) {
            throw new LogicException(sprintf('[%s] has no key, so a forged write cannot find its row.', $model::class));
        }

        $connection = $model->getConnection();
        $quoted = preg_quote($connection->getQueryGrammar()->wrapTable($model->getTable()), '/');
        $unquoted = preg_quote($connection->getTablePrefix().$model->getTable(), '/');
        $source = '/\b(?i:from|join)\s+(?:'.$quoted.'|(?i:'.$unquoted.')\b)/';
        $changed = false;

        DB::listen(function (QueryExecuted $query) use ($model, $key, $change, $connection, $source, &$changed): void {
            if ($changed || $this->forgingOnFirstRead || $query->connectionName !== $connection->getName() || preg_match('/^\s*(?:select|with)\b/i', $query->sql) !== 1 || preg_match($source, $query->sql) !== 1) {
                return;
            }

            $changed = true;
            $this->forgingOnFirstRead = true;

            try {
                $change($model->newQueryWithoutScopes()->whereKey($key)->toBase());
            } finally {
                $this->forgingOnFirstRead = false;
            }
        });
    }
}
