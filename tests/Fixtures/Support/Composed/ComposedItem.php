<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Composed;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\HasBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;

/**
 * @property string $id
 * @property string|null $parent_id
 * @property string|null $rank_key
 * @property bool $hidden
 * @property string $concurrency_revision
 * @property-read int|null $position
 */
#[OrderedIn(ComposedItem::class)]
class ComposedItem extends Model implements Ordered, OrdersRelations, Revisioned
{
    /** @use HasBuilder<Builder<static>> */
    use HasBuilder;

    use HasConcurrencyRevisions;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasOrderedRelations;
    use HasUuids;
    use IsOrdered;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return Builder<static>
     */
    public function newQueryWithoutScopes()
    {
        return $this->selectPositionOnKeyQuery(parent::newQueryWithoutScopes()); // @phpstan-ignore return.type
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    #[OrdersRelation(ComposedItemRevision::ChildrenOrder, membership: ['composed_items.hidden'])]
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('composed_items.hidden', false);
    }
}
