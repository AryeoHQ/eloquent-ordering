<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Playlists;

use DateTimeImmutable;
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

    public const int DISC = 1;

    public const int CODE = 1;

    public const string MOOD = "o'clock";

    public const array FORMATS = ['flac', 'mp3'];

    public const string SOURCE = 'studio';

    public const string RELEASED_BY = '2026-01-01 00:00:00';

    public const string LABEL = '0f1e2d3c-4b5a-4968-8776-655443322110';

    protected $guarded = [];

    private static null|DateTimeImmutable $releasedBy = null;

    /**
     * @return HasMany<Track, $this>
     */
    #[OrdersRelation(PlaylistRevision::TracksOrder, membership: [
        'tracks.kind',
        'playlists.kind',
        'tracks.disc',
        'tracks.code',
        'tracks.mood',
        'tracks.explicit',
        'tracks.format',
        'tracks.source',
        'tracks.released_at',
        'tracks.label_id',
    ])]
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)
            ->where('tracks.kind', $this->kind)
            ->where('tracks.disc', self::DISC)
            ->where('tracks.code', self::CODE)
            ->where('tracks.mood', self::MOOD)
            ->where('tracks.explicit', false)
            ->whereIn('tracks.format', self::FORMATS)
            ->whereIn('tracks.source', [self::SOURCE, null])
            ->where('tracks.released_at', '<=', self::$releasedBy ??= new DateTimeImmutable(self::RELEASED_BY))
            ->where('tracks.label_id', self::LABEL);
    }
}
