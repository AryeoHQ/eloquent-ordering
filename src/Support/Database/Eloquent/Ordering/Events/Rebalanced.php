<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;

final readonly class Rebalanced implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<int|string>  $ids
     */
    public function __construct(
        public Model&Revisioned $parent,
        public NamesConcurrencyRevision $revision,
        public string $digest,
        public array $ids,
    ) {}
}
