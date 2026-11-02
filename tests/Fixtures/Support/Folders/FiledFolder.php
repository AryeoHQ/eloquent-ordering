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
use Tests\Fixtures\Support\Documents\FiledDocument;

/**
 * @property int $id
 * @property string|null $name
 * @property string $concurrency_revision
 */
class FiledFolder extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    public const string ARCHIVE = 'Archive';

    protected $table = 'folders';

    protected $guarded = [];

    /**
     * @return HasMany<FiledDocument, $this>
     */
    #[OrdersRelation(FolderRevision::DocumentsOrder, membership: ['documents.home_folder_id'])]
    public function documents(): HasMany
    {
        return $this->hasMany(FiledDocument::class, 'folder_id')->whereIn(
            'documents.home_folder_id',
            $this->getConnection()->table('folders')->select('folders.id')->where('folders.name', self::ARCHIVE),
        );
    }
}
