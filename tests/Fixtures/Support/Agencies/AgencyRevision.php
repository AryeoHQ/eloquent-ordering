<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Agencies;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum AgencyRevision: string implements NamesConcurrencyRevision
{
    case CustomersOrder = 'customers_order';
}
