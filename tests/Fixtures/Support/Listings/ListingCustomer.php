<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Events\ModelUpdated;

/**
 * @property string $id
 * @property int $listing_id
 * @property int $customer_id
 * @property string|null $rank_key
 * @property string|null $title
 * @property-read int|null $position
 */
#[OrderedIn(Listing::class)]
class ListingCustomer extends Pivot implements Ordered
{
    use HasUuids;
    use IsOrdered;
    use SoftDeletes;

    protected $table = 'listing_customer';

    protected $dispatchesEvents = ['updated' => ModelUpdated::class];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
