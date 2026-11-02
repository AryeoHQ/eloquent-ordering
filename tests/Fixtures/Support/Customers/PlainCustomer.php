<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Customers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Support\Database\Eloquent\Concurrency\Contracts\LocksParentsFirst;
use Support\Database\Eloquent\Ordering\Attributes\OrderedThrough;
use Support\Database\Eloquent\Ordering\Provides\OrdersThroughPivots;
use Tests\Fixtures\Support\Agencies\PlainAgency;
use Tests\Fixtures\Support\Agencies\PlainAgencyCustomer;
use Tests\Fixtures\Support\Listings\KeyedListing;
use Tests\Fixtures\Support\Listings\PivotedListing;
use Tests\Fixtures\Support\Listings\TitledListing;
use Tests\Fixtures\Support\Tours\KeyedTour;

/**
 * @property int $id
 * @property string|null $name
 */
#[OrderedThrough(PlainAgency::class, TitledListing::class, PivotedListing::class, KeyedListing::class, KeyedTour::class)]
class PlainCustomer extends Model implements LocksParentsFirst
{
    use OrdersThroughPivots;

    protected $table = 'customers';

    protected $guarded = [];

    /**
     * @return BelongsToMany<PlainAgency, $this, PlainAgencyCustomer>
     */
    public function agencies(): BelongsToMany
    {
        return $this->belongsToMany(PlainAgency::class, 'agency_customer', 'customer_id', 'agency_id')
            ->using(PlainAgencyCustomer::class)
            ->wherePivotNull('deleted_at');
    }
}
