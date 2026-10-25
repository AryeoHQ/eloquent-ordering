<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Tours;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum TourRevision: string implements NamesConcurrencyRevision
{
    case ScenesOrder = 'scenes_order';
    case CustomersOrder = 'customers_order';
}
