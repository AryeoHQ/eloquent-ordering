<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Shelves;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Binders\Binder;

/**
 * @property int $id
 * @property int $concurrency_revision
 * @property int $binders_order_concurrency_revision
 */
#[OrdersRelation(ShelfRevision::BindersOrder, relation: 'binders')]
class Shelf extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $guarded = [];

    /**
     * @return HasMany<Binder, $this>
     */
    public function binders(): HasMany
    {
        return $this->hasMany(Binder::class);
    }
}
