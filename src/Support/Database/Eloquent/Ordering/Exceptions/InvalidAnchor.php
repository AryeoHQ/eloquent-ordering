<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Ordering\OrderedRelation;

final class InvalidAnchor extends InvalidArgumentException
{
    public function __construct(Model $parent, NamesConcurrencyRevision $revision, int|string $anchor)
    {
        parent::__construct(sprintf(
            'The anchor [%s] is not a current member of the [%s] order of [%s:%s].',
            $anchor,
            $revision->value,
            OrderedRelation::typeOf($parent),
            $parent->getKey(),
        ));
    }
}
