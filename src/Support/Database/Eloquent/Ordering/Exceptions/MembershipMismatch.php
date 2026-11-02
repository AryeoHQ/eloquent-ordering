<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

final class MembershipMismatch extends InvalidArgumentException
{
    public function __construct(Model $parent, NamesConcurrencyRevision $revision)
    {
        parent::__construct(sprintf(
            'The ids must list each current member of the [%s] order of [%s:%s] exactly once.',
            $revision->value,
            $parent->getMorphClass(),
            $parent->getKey(),
        ));
    }
}
