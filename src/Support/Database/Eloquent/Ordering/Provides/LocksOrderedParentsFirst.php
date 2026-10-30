<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Concurrency\Contracts\LocksParentsFirst;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements LocksParentsFirst
 */
trait LocksOrderedParentsFirst
{
    /**
     * @return list<Model>
     */
    public function parentsToLockFirst(): array
    {
        $parents = [];

        foreach (['orderingParentsToLockFirst', 'pivotParentsToLockFirst'] as $half) {
            foreach (method_exists($this, $half) ? $this->{$half}() : [] as $parent) {
                if (! array_any($parents, static fn (Model $named): bool => $named->is($parent))) {
                    $parents[] = $parent;
                }
            }
        }

        return $parents;
    }
}
