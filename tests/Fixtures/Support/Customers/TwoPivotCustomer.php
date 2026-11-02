<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Customers;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Concurrency\Contracts\LocksParentsFirst;
use Support\Database\Eloquent\Ordering\Attributes\OrderedThrough;
use Support\Database\Eloquent\Ordering\Provides\OrdersThroughPivots;
use Tests\Fixtures\Support\Listings\TwoPivotListing;

/**
 * @property int $id
 */
#[OrderedThrough(TwoPivotListing::class)]
class TwoPivotCustomer extends Model implements LocksParentsFirst
{
    use OrdersThroughPivots;

    protected $table = 'customers';

    protected $guarded = [];
}
