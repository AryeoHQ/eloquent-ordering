<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Concurrency\Concerns\WritesInTransactions;
use Support\Database\Eloquent\Concurrency\Contracts\LocksParentsFirst;
use Support\Database\Eloquent\Ordering\Providers\Provider;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements LocksParentsFirst
 */
trait OrdersThroughPivots
{
    use WritesInTransactions;

    /**
     * @return list<Model>
     */
    public function parentsToLockFirst(): array
    {
        return Provider::parentsReachedBy($this);
    }
}
