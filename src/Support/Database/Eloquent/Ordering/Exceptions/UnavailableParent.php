<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class UnavailableParent extends RuntimeException
{
    public function __construct(public readonly Model $parent, public readonly ParentUnavailability $reason)
    {
        parent::__construct(sprintf(
            '[%s:%s] is %s, so no write can place a member in its order.',
            $parent->getMorphClass(),
            $parent->getKey(),
            $reason->value,
        ));
    }
}
