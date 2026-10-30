<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use InvalidArgumentException;
use Support\Database\Eloquent\Ordering\OrderedRelation;

final class MembershipMismatch extends InvalidArgumentException
{
    public function __construct(OrderedRelation $relation)
    {
        parent::__construct(sprintf(
            'The ids must list each current member of the [%s] order of [%s:%s] exactly once.',
            $relation->revision->value,
            $relation->parent->getMorphClass(),
            $relation->parent->getKey(),
        ));
    }
}
