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
            '[%s:%s] was read without its position. A query with a select list other than * or <table>.*, a group, a having, distinct, a union, or withoutPositions() selects none. So does every query of a model with $withCount, a query after withoutGlobalScopes(), and, on a BelongsToMany relation to an ordered model, paginate(), simplePaginate(), cursorPaginate(), and the iteration methods such as chunk(), each(), lazy() and cursor(). A locking read outside a transaction selects none, because its row locks end with its statement. So does a locking read whose lock lets another transaction update the row, such as for key share, and a lock clause other than for update, for no key update or for share, each with at most nowait or skip locked. isset() and ?? read the position too, so they throw on such a row.',
            $item::class,
            $item->getKey(),
        ));
    }
}
