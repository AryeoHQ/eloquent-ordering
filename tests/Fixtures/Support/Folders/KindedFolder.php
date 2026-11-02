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
use Tests\Fixtures\Support\Documents\DocumentKind;
use Tests\Fixtures\Support\Documents\KindedDocument;

/**
 * @property int $id
 * @property DocumentKind|null $kind
 * @property string $concurrency_revision
 */
class KindedFolder extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $table = 'folders';

    protected $guarded = [];

    protected $casts = ['kind' => DocumentKind::class];

    /**
     * @return HasMany<KindedDocument, $this>
     */
    #[OrdersRelation(FolderRevision::DocumentsOrder, membership: ['documents.kind', 'folders.kind'])]
    public function documents(): HasMany
    {
        return $this->hasMany(KindedDocument::class, 'folder_id')->where('documents.kind', $this->kind);
    }
}
