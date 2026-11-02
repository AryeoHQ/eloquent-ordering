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
use Tests\Fixtures\Support\Agencies\AgencyCustomer;
use Tests\Fixtures\Support\Customers\TwoPivotCustomer;

/**
 * @property int $id
 * @property string $concurrency_revision
 */
class TwoPivotListing extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $table = 'listings';

    protected $guarded = [];

    /**
     * @return BelongsToMany<TwoPivotCustomer, $this, ListingCustomer>
     */
    #[OrdersRelation(ListingRevision::CustomersOrder)]
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(TwoPivotCustomer::class, 'listing_customer', 'listing_id', 'customer_id')
            ->using(ListingCustomer::class)
            ->wherePivotNull('deleted_at');
    }

    /**
     * @return BelongsToMany<TwoPivotCustomer, $this, AgencyCustomer>
     */
    #[OrdersRelation(ListingRevision::ScenesOrder)]
    public function agencyCustomers(): BelongsToMany
    {
        return $this->belongsToMany(TwoPivotCustomer::class, 'agency_customer', 'agency_id', 'customer_id')
            ->using(AgencyCustomer::class)
            ->wherePivotNull('deleted_at');
    }
}
