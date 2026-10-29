<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Tours;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Customers\Customerable;
use Tests\Fixtures\Support\Events\ModelUpdated;
use Tests\Fixtures\Support\Scenes\Scene;

/**
 * @property int $id
 * @property string|null $title
 * @property int $concurrency_revision
 * @property int $scenes_order_concurrency_revision
 * @property int $customers_order_concurrency_revision
 */
#[OrdersRelation(TourRevision::ScenesOrder, relation: 'scenes')]
#[OrdersRelation(TourRevision::CustomersOrder, relation: 'customers')]
class Tour extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $guarded = [];

    protected $dispatchesEvents = ['updated' => ModelUpdated::class];

    /**
     * @return MorphMany<Scene, $this>
     */
    public function scenes(): MorphMany
    {
        return $this->morphMany(Scene::class, 'sceneable');
    }

    /**
     * @return MorphToMany<Customer, $this, Customerable>
     */
    public function customers(): MorphToMany
    {
        return $this->morphToMany(Customer::class, 'customerable')
            ->using(Customerable::class)
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }
}
