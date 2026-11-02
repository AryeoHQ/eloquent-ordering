<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Concurrency\Concerns\WritesInTransactions;
use Support\Database\Eloquent\Concurrency\Contracts\LocksParentsFirst;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Support\Database\Eloquent\Ordering\Scopes\SelectsPosition;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements LocksParentsFirst
 */
trait OrdersThroughPivots
{
    use LocksOrderedParentsFirst;
    use WritesInTransactions;

    public static function bootOrdersThroughPivots(): void
    {
        Provider::listenToPivotParentsOf(static::class);

        static::addGlobalScope(new SelectsPosition);

        foreach (OrderedRelation::orderedThrough(static::class) as $parent) {
            $parent::addGlobalScope(new SelectsPosition);
        }
    }

    /**
     * @return list<Model>
     */
    protected function pivotParentsToLockFirst(): array
    {
        return Provider::parentsReachedBy($this);
    }
}
