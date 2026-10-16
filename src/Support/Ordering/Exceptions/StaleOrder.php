<?php

declare(strict_types=1);

namespace Support\Ordering\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Support\Ordering\OrderedCollection;

final class StaleOrder extends RuntimeException
{
    public static function version(OrderedCollection $collection, int $expectedVersion): self
    {
        return new self(sprintf(
            'The [%s] order of [%s:%s] is no longer at version [%d].',
            $collection->name,
            $collection->parent->getMorphClass(),
            $collection->parent->getKey(),
            $expectedVersion,
        ));
    }

    public static function member(Model $item): self
    {
        return new self(sprintf(
            '[%s:%s] left the order it was read from.',
            $item->getMorphClass(),
            $item->getKey(),
        ));
    }
}
