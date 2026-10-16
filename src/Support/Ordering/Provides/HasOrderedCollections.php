<?php

declare(strict_types=1);

namespace Support\Ordering\Provides;

use Support\Ordering\OrderedCollection;

trait HasOrderedCollections
{
    public function orderedCollection(string $collection): OrderedCollection
    {
        return new OrderedCollection($this, $collection);
    }
}
