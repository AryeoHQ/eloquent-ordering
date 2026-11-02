<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class UnselectedPosition extends LogicException
{
    public function __construct(public readonly Model $item)
    {
        parent::__construct(sprintf(
            '[%s:%s] was read without its position. A query with an explicit select list, a group, a having, distinct, a union, or withoutPositions() selects none.',
            $item::class,
            $item->getKey(),
        ));
    }
}
