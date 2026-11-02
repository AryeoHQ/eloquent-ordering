<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Customers;

use Support\Database\Eloquent\Ordering\Attributes\OrderedThrough;
use Tests\Fixtures\Support\Listings\TwiceOrderingListing;

#[OrderedThrough(TwiceOrderingListing::class, TwiceOrderingListing::class)]
class TwiceOrderedCustomer extends Customer
{
    protected $table = 'customers';
}
