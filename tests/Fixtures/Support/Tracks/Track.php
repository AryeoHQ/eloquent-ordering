<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Tracks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Playlists\Playlist;

/**
 * @property int $id
 * @property int $playlist_id
 * @property string|null $kind
 * @property string|null $rank_key
 * @property-read int|null $position
 */
#[OrderedIn(Playlist::class)]
class Track extends Model implements Ordered
{
    use IsOrdered;

    protected $guarded = [];

    /**
     * @return BelongsTo<Playlist, $this>
     */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }
}
