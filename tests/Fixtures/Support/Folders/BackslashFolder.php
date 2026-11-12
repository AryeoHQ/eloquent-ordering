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
use Tests\Fixtures\Support\Documents\BackslashDocument;

/**
 * @property int $id
 * @property string $concurrency_revision
 */
class BackslashFolder extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    public static string $locale = '';

    protected $table = 'folders';

    protected $guarded = [];

    /**
     * @return HasMany<BackslashDocument, $this>
     */
    #[OrdersRelation(FolderRevision::DocumentsOrder, membership: ['documents.locale'])]
    public function documents(): HasMany
    {
        return $this->hasMany(BackslashDocument::class, 'folder_id')->where('documents.locale', self::$locale);
    }
}
