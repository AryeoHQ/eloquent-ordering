<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TKey of array-key
 * @template TModel of Model
 *
 * @extends Collection<TKey, TModel>
 */
class OrderedCollection extends Collection
{
    /**
     * @return $this
     */
    public function loadPositions(): static
    {
        OrderedRelation::loadPositions($this->all());

        return $this;
    }
}
