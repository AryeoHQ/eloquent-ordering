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
use Tests\Fixtures\Support\Customers\CountedCustomer;

/**
 * @property int $id
 * @property string $concurrency_revision
 */
class CountingListing extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $table = 'listings';

    protected $guarded = [];

    /**
     * @return BelongsToMany<CountedCustomer, $this, CountingListingCustomer>
     */
    #[OrdersRelation(ListingRevision::CustomersOrder)]
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(CountedCustomer::class, 'listing_customer', 'listing_id', 'customer_id')
            ->using(CountingListingCustomer::class)
            ->wherePivotNull('deleted_at');
    }
}
