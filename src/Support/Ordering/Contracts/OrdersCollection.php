<?php

declare(strict_types=1);

namespace Support\Ordering\Contracts;

use Support\Ordering\OrderedCollection;

interface OrdersCollection
{
    public function orderedCollection(string $collection): OrderedCollection;
}
