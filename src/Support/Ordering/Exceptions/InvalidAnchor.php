<?php

declare(strict_types=1);

namespace Support\Ordering\Exceptions;

use InvalidArgumentException;
use Support\Ordering\OrderedCollection;

final class InvalidAnchor extends InvalidArgumentException
{
    public function __construct(OrderedCollection $collection, int|string $anchor)
    {
        parent::__construct(sprintf(
            'The anchor [%s] is not a current member of the [%s] order of [%s:%s].',
            $anchor,
            $collection->name,
            $collection->parent->getMorphClass(),
            $collection->parent->getKey(),
        ));
    }
}
