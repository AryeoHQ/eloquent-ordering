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
use Tests\Fixtures\Support\Customers\Customer;

/**
 * @property int $id
 * @property int $concurrency_revision
 * @property int $customers_order_concurrency_revision
 */
#[OrdersRelation(AgencyRevision::CustomersOrder, relation: 'customers')]
class Agency extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $guarded = [];

    /**
     * @return BelongsToMany<Customer, $this, AgencyCustomer>
     */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'agency_customer')
            ->using(AgencyCustomer::class)
            ->wherePivotNull('deleted_at');
    }
}
