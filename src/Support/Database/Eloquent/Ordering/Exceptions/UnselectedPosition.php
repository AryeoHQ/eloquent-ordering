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
            '[%s:%s] was read without its position. A query with an explicit select list, a group, a having, distinct, a union, or withoutPositions() selects none. So does every query of a model with $withCount, a query after withoutGlobalScopes(), and paginate(), simplePaginate() or chunk() on a BelongsToMany relation to an ordered model. isset() and ?? read the position too, so they throw on such a row.',
            $item::class,
            $item->getKey(),
        ));
    }
}
