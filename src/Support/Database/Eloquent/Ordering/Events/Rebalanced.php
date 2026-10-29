<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Support\Database\Eloquent\Ordering\OrderedRelation;

final readonly class Rebalanced implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<int|string>  $ids
     */
    public function __construct(
        public OrderedRelation $relation,
        public int $revision,
        public array $ids,
    ) {}
}
