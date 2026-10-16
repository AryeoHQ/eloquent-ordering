<?php

declare(strict_types=1);

namespace Support\Ordering\Exceptions;

use RuntimeException;
use Support\Ordering\OrderedCollection;

final class StaleOrder extends RuntimeException
{
    public function __construct(OrderedCollection $collection, int $expectedVersion)
    {
        parent::__construct(sprintf(
            'The [%s] order of [%s:%s] is no longer at version [%d].',
            $collection->name,
            $collection->parent->getMorphClass(),
            $collection->parent->getKey(),
            $expectedVersion,
        ));
    }
}
