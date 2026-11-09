<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Drawers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Notes\CollectedNote;

/**
 * @property int $id
 * @property string $concurrency_revision
 */
class CollectingDrawer extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;
    use SoftDeletes;

    protected $table = 'drawers';

    protected $guarded = [];

    /**
     * @return HasMany<CollectedNote, $this>
     */
    #[OrdersRelation(DrawerRevision::NotesOrder)]
    public function notes(): HasMany
    {
        return $this->hasMany(CollectedNote::class, 'drawer_id');
    }
}
