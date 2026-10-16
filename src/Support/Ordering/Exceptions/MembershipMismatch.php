<?php

declare(strict_types=1);

namespace Support\Ordering\Exceptions;

use InvalidArgumentException;
use Support\Ordering\OrderedCollection;

final class MembershipMismatch extends InvalidArgumentException
{
    public function __construct(OrderedCollection $collection)
    {
        parent::__construct(sprintf(
            'The ids must list each current member of the [%s] order of [%s:%s] exactly once.',
            $collection->name,
            $collection->parent->getMorphClass(),
            $collection->parent->getKey(),
        ));
    }
}
