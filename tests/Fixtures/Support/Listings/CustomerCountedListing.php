<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Customers\ListingCountedCustomer;

/**
 * @property int $id
 * @property string $concurrency_revision
 */
class CustomerCountedListing extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $table = 'listings';

    protected $guarded = [];

    /**
     * @return BelongsToMany<ListingCountedCustomer, $this, CustomerCountedListingCustomer>
     */
    #[OrdersRelation(ListingRevision::CustomersOrder)]
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(ListingCountedCustomer::class, 'listing_customer', 'listing_id', 'customer_id')
            ->using(CustomerCountedListingCustomer::class)
            ->wherePivotNull('deleted_at');
    }
}
