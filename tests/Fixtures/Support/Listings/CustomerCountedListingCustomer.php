<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;

/**
 * @property string $id
 * @property int $listing_id
 * @property int $customer_id
 * @property string|null $rank_key
 * @property-read int|null $position
 */
#[OrderedIn(CustomerCountedListing::class)]
class CustomerCountedListingCustomer extends Pivot implements Ordered
{
    use HasUuids;
    use IsOrdered;
    use SoftDeletes;

    protected $table = 'listing_customer';
}
