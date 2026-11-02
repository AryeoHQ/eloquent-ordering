<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Agencies;

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
 * @property string $concurrency_revision
 */
class PlainAgency extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $table = 'agencies';

    protected $guarded = [];

    /**
     * @return BelongsToMany<PlainCustomer, $this, PlainAgencyCustomer>
     */
    #[OrdersRelation(AgencyRevision::CustomersOrder)]
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(PlainCustomer::class, 'agency_customer', 'agency_id', 'customer_id')
            ->using(PlainAgencyCustomer::class)
            ->wherePivotNull('deleted_at');
    }
}
