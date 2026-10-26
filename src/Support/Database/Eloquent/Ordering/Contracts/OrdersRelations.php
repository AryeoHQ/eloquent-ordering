<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Contracts;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Ordering\OrderedRelation;

interface OrdersRelations
{
    public function orderedRelation(NamesConcurrencyRevision $revision): OrderedRelation;
}
