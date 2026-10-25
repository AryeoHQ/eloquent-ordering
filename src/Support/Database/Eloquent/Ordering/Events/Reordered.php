<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Support\Database\Eloquent\Ordering\OrderChange;

final readonly class Reordered implements ShouldDispatchAfterCommit
{
    public function __construct(public OrderChange $change) {}
}
