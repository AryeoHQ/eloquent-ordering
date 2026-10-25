<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use InvalidArgumentException;
use Support\Database\Eloquent\Ordering\OrderedRelation;

final class InvalidAnchor extends InvalidArgumentException
{
    public function __construct(OrderedRelation $relation, int|string $anchor)
    {
        parent::__construct(sprintf(
            'The anchor [%s] is not a current member of the [%s] order of [%s:%s].',
            $anchor,
            $relation->revision->value,
            $relation->parent->getMorphClass(),
            $relation->parent->getKey(),
        ));
    }
}
