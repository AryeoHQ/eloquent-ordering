<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

final readonly class OrderChange
{
    /**
     * @param  list<array{id: int|string, position: int}>  $order
     * @param  list<array{id: int|string, position: int|null, previous_position: int|null}>  $changed
     */
    public function __construct(
        public OrderedRelation $relation,
        public array $order,
        public array $changed,
        public int $revision,
        public null|OrderChange $source = null,
    ) {}
}
