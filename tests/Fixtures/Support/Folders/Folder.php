<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Folders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Documents\Document;

/**
 * @property int $id
 * @property string $concurrency_revision
 */
class Folder extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $guarded = [];

    /**
     * @return HasMany<Document, $this>
     */
    #[OrdersRelation(FolderRevision::DocumentsOrder, membership: ['documents.home_folder_id'])]
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->where('documents.home_folder_id', $this->id);
    }
}
