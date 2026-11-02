<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Albums;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Photos\Photo;

/**
 * @property int $id
 * @property string $concurrency_revision
 */
class Album extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $guarded = [];

    /**
     * @return HasMany<Photo, $this>
     */
    #[OrdersRelation(AlbumRevision::PhotosOrder)]
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }
}
