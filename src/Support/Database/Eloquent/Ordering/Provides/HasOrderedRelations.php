<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Concurrency\Concerns\WritesInTransactions;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Providers\Provider;

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
            if (OrderedRelation::refilters($parent)) {
                self::lockOrderingParentsFirst($parent);
            }

            $parent->refilteredOrders = OrderedRelation::membersBeforeRefilter($parent);
            OrderedRelation::preparedParent($parent);
        });

        static::saved(static function (self $parent): void {
            $refiltered = $parent->refilteredOrders;
            $parent->refilteredOrders = [];

            foreach ($refiltered as [$revision, $before]) {
                (new OrderedRelation($parent, $revision))->refilteredFrom($before);
            }
        });
    }

    private static function lockOrderingParentsFirst(Model $parent): void
    {
        if (! $parent instanceof Ordered) {
            return;
        }

        if (Provider::locksPivotParentsOf($parent, deleting: false)) {
            OrderedRelation::lockParentsFirst($parent);
        } elseif ($parent->isDirty(OrderedRelation::watchedColumns($parent))) {
            OrderedRelation::lockParentsFirstUnlessLocked($parent);
        }
    }
}
