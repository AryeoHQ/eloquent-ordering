<?php

declare(strict_types=1);

namespace Support\Ordering;

final readonly class OrderChange
{
    /**
     * @param  list<array{id: int|string, position: int}>  $order
     * @param  list<array{id: int|string, position: int|null, previous_position: int|null}>  $changed
     */
    public function __construct(
        public OrderedCollection $collection,
        public array $order,
        public array $changed,
        public int $version,
        public string $tag,
        public OrderChange|null $source = null,
    ) {}
}
