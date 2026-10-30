<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Concurrency\Concerns\WritesInTransactions;

/**
 * @phpstan-require-extends Model
 */
trait OrdersThroughPivots
{
    use WritesInTransactions;
}
