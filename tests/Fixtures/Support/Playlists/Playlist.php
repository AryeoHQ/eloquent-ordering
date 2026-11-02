<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Playlists;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Tracks\Track;

/**
 * @property int $id
 * @property string|null $kind
 * @property string $concurrency_revision
 */
class Playlist extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $guarded = [];

    /**
     * @return HasMany<Track, $this>
     */
    #[OrdersRelation(PlaylistRevision::TracksOrder, membership: ['tracks.kind', 'playlists.kind'])]
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)->where('tracks.kind', $this->kind);
    }
}
