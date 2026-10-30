<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Illuminate\Database\Eloquent\Model;
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

    public function orderedRelation(NamesConcurrencyRevision $revision): OrderedRelation
    {
        return new OrderedRelation($this, $revision);
    }
}
