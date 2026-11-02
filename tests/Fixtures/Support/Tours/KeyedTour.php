<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Tours;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Customers\Customerable;
use Tests\Fixtures\Support\Customers\PlainCustomer;

/**
 * @property int $id
 * @property string|null $title
 * @property string $concurrency_revision
 */
class KeyedTour extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    public string $ownerKey = 'id';

    protected $table = 'tours';

    protected $guarded = [];

    /**
     * @return MorphToMany<PlainCustomer, $this, Customerable>
     */
    #[OrdersRelation(TourRevision::CustomersOrder)]
    public function customers(): MorphToMany
    {
        return $this->morphToMany(PlainCustomer::class, 'customerable', 'customerables', relatedPivotKey: 'customer_id', parentKey: $this->ownerKey)->using(Customerable::class);
    }
}
