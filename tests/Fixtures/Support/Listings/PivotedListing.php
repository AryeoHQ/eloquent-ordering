<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Customers\PlainCustomer;

/**
 * @property int $id
 * @property string|null $title
 * @property string $concurrency_revision
 */
class PivotedListing extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    /** @var class-string<Pivot&Ordered> */
    public static string $pivot = ListingCustomer::class;

    protected $table = 'listings';

    protected $guarded = [];

    /**
     * @return BelongsToMany<PlainCustomer, $this, Pivot&Ordered>
     */
    #[OrdersRelation(ListingRevision::CustomersOrder)]
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(PlainCustomer::class, 'listing_customer', 'listing_id', 'customer_id')
            ->using(self::$pivot)
            ->wherePivotNull('deleted_at');
    }
}
