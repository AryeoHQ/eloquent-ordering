<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Binders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Pages\Page;
use Tests\Fixtures\Support\Shelves\Shelf;

/**
 * @property int $id
 * @property int $shelf_id
 * @property string|null $kind
 * @property string|null $rank_key
 * @property int $concurrency_revision
 * @property int $pages_order_concurrency_revision
 * @property-read int|null $position
 */
#[OrderedIn(Shelf::class)]
#[OrdersRelation(BinderRevision::PagesOrder, relation: 'pages', membership: ['pages.kind', 'binders.kind'])]
class Binder extends Model implements Ordered, OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;
    use IsOrdered;

    protected $guarded = [];

    /**
     * @return BelongsTo<Shelf, $this>
     */
    public function shelf(): BelongsTo
    {
        return $this->belongsTo(Shelf::class);
    }

    /**
     * @return HasMany<Page, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->where('pages.kind', $this->kind);
    }
}
