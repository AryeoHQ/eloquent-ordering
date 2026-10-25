<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements OrdersRelations
 * @phpstan-require-implements Revisioned
 */
trait HasOrderedRelations
{
    use ResolvesOrderedRelations;
}
