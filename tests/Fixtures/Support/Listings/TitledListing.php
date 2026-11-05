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
use Tests\Fixtures\Support\Customers\PlainCustomer;

/**
 * @property int $id
 * @property string|null $title
 * @property string $concurrency_revision
 */
class TitledListing extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    public const string TITLE = 'Shown';

    protected $table = 'listings';

    protected $guarded = [];

    /**
     * @return BelongsToMany<PlainCustomer, $this, ListingCustomer>
     */
    #[OrdersRelation(ListingRevision::CustomersOrder, membership: ['listing_customer.title'])]
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(PlainCustomer::class, 'listing_customer', 'listing_id', 'customer_id')
            ->using(ListingCustomer::class)
            ->wherePivotNull('deleted_at')
            ->wherePivot('title', self::TITLE);
    }
}
