<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Support\Database\Eloquent\Ordering\OrderedRelation;

final class UnavailableParent extends RuntimeException
{
    public function __construct(public readonly Model $parent, public readonly ParentUnavailability $reason)
    {
        parent::__construct(sprintf(
            '[%s:%s] is %s, so no write can place a member in its order.',
            OrderedRelation::typeOf($parent),
            $parent->getKey(),
            $reason->value,
        ));
    }
}
