<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Contracts;

use Support\Database\Eloquent\Concurrency\Contracts\LocksParentsFirst;
use Support\Database\Eloquent\Ordering\OrderChange;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Placement;

interface Ordered extends LocksParentsFirst
{
    public function placeAt(Placement $placement): static;

    public function move(Placement $placement, null|int $expected): OrderChange;

    public function orderingRelation(): OrderedRelation;
}
