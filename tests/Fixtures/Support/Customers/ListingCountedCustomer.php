<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Customers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Support\Database\Eloquent\Concurrency\Contracts\LocksParentsFirst;
use Support\Database\Eloquent\Ordering\Attributes\OrderedThrough;
use Support\Database\Eloquent\Ordering\Provides\OrdersThroughPivots;
use Tests\Fixtures\Support\Listings\CustomerCountedListing;
use Tests\Fixtures\Support\Listings\CustomerCountedListingCustomer;

/**
 * @property int $id
 * @property-read int $listings_count
 */
#[OrderedThrough(CustomerCountedListing::class)]
class ListingCountedCustomer extends Model implements LocksParentsFirst
{
    use OrdersThroughPivots;

    protected $table = 'customers';

    protected $guarded = [];

    protected $withCount = ['listings'];

    /**
     * @return BelongsToMany<CustomerCountedListing, $this, CustomerCountedListingCustomer>
     */
    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(CustomerCountedListing::class, 'listing_customer', 'customer_id', 'listing_id')
            ->using(CustomerCountedListingCustomer::class)
            ->wherePivotNull('deleted_at');
    }
}
