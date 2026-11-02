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
use Tests\Fixtures\Support\Documents\LocalizedDocument;

/**
 * @property int $id
 * @property string $concurrency_revision
 */
class LocalizedFolder extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $table = 'folders';

    protected $guarded = [];

    /**
     * @return HasMany<LocalizedDocument, $this>
     */
    #[OrdersRelation(FolderRevision::DocumentsOrder, membership: ['documents.locale'])]
    public function documents(): HasMany
    {
        return $this->hasMany(LocalizedDocument::class, 'folder_id')->where('documents.locale', app()->getLocale());
    }
}
