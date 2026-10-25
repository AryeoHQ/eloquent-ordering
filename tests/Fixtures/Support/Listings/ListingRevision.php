<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum ListingRevision: string implements NamesConcurrencyRevision
{
    case CustomersOrder = 'customers_order';
    case ScenesOrder = 'scenes_order';
}
