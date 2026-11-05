<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;

final readonly class OrderChange
{
    /**
     * @param  list<int|string>  $order
     * @param  list<PositionChange>  $changed
     */
    public function __construct(
        public Model&Revisioned $parent,
        public NamesConcurrencyRevision $revision,
        public array $order,
        public array $changed,
        public string $digest,
        public null|OrderChange $source = null,
    ) {}
}
