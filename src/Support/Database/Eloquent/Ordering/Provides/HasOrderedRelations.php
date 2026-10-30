<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Support\Database\Eloquent\Concurrency\Concerns\WritesInTransactions;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\OrderedRelation;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements OrdersRelations
 * @phpstan-require-implements Revisioned
 */
trait HasOrderedRelations
{
    use WritesInTransactions;

    /** @var list<array{NamesConcurrencyRevision, list<int|string>}> */
    private array $refilteredOrders = [];

    public static function bootHasOrderedRelations(): void
    {
        static::saving(static function (self $parent): void {
            $parent->refilteredOrders = OrderedRelation::membersBeforeRefilter($parent);
            OrderedRelation::preparedParent($parent);
        });

        static::updating(static function (self $parent): void {
            foreach ($parent->refilteredOrders as [$revision]) {
                $column = $parent->concurrencyRevisionColumn($revision);

                $parent->setAttribute($column, new Expression(sprintf('%s + 1', $parent->getConnection()->getQueryGrammar()->wrap($column))));
            }
        });

        static::saved(static function (self $parent): void {
            $refiltered = $parent->refilteredOrders;
            $parent->refilteredOrders = [];

            foreach ($refiltered as [$revision, $before]) {
                $parent->orderedRelation($revision)->refilteredFrom($before);
            }
        });
    }

    public function orderedRelation(NamesConcurrencyRevision $revision): OrderedRelation
    {
        return new OrderedRelation($this, $revision);
    }
}
