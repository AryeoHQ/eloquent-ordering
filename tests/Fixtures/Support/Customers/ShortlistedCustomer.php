<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Customers;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Support\Database\Eloquent\Ordering\Attributes\OrderedThrough;
use Tests\Fixtures\Support\Listings\Shortlist;
use Tests\Fixtures\Support\Listings\ShortlistCustomer;

#[OrderedThrough(Shortlist::class)]
class ShortlistedCustomer extends Customer
{
    protected $table = 'customers';

    /**
     * @return BelongsToMany<Shortlist, $this, ShortlistCustomer>
     */
    public function shortlists(): BelongsToMany
    {
        return $this->belongsToMany(Shortlist::class, 'listing_customer', 'customer_id', 'listing_id')
            ->using(ShortlistCustomer::class)
            ->wherePivotNull('deleted_at');
    }
}
