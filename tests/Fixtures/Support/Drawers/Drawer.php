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
use Tests\Fixtures\Support\Notes\Note;

/**
 * @property int $id
 * @property string $concurrency_revision
 */
class Drawer extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return HasMany<Note, $this>
     */
    #[OrdersRelation(DrawerRevision::NotesOrder)]
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }
}
