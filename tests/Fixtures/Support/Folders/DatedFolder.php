<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Folders;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Documents\DatedDocument;

/**
 * @property int $id
 * @property string $concurrency_revision
 */
class DatedFolder extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    public const string PUBLISHED_BY = '2026-01-01 00:00:00';

    protected $table = 'folders';

    protected $guarded = [];

    private static null|DateTimeImmutable $publishedBy = null;

    /**
     * @return HasMany<DatedDocument, $this>
     */
    #[OrdersRelation(FolderRevision::DocumentsOrder, membership: ['documents.published_at'])]
    public function documents(): HasMany
    {
        return $this->hasMany(DatedDocument::class, 'folder_id')->where('documents.published_at', '<=', self::$publishedBy ??= new DateTimeImmutable(self::PUBLISHED_BY));
    }
}
