<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

final readonly class OrderChange
{
    /**
     * @param  list<int|string>  $order
     * @param  list<PositionChange>  $changed
     */
    public function __construct(
        public OrderedRelation $relation,
        public array $order,
        public array $changed,
        public int $revision,
        public null|OrderChange $source = null,
    ) {}
}
