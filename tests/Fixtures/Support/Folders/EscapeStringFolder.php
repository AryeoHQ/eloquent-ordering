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
use Tests\Fixtures\Support\Documents\EscapeStringDocument;

/**
 * @property int $id
 * @property string|null $name
 * @property string|null $kind
 * @property string $concurrency_revision
 */
class EscapeStringFolder extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    public const string ARCHIVE = 'Archive';

    public const string KIND = "it's?";

    protected $table = 'folders';

    protected $guarded = [];

    /**
     * @return HasMany<EscapeStringDocument, $this>
     */
    #[OrdersRelation(FolderRevision::DocumentsOrder, membership: ['documents.home_folder_id'])]
    public function documents(): HasMany
    {
        return $this->hasMany(EscapeStringDocument::class, 'folder_id')->whereIn(
            'documents.home_folder_id',
            $this->getConnection()->table('folders')->select('folders.id')->whereRaw("folders.kind = E'it\\'s?'")->where('folders.name', self::ARCHIVE),
        );
    }
}
