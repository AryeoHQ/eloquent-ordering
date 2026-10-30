<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Photos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Albums\Album;
use Tests\Fixtures\Support\Boards\Board;

/**
 * @property int $id
 * @property int|null $board_id
 * @property int|null $album_id
 * @property string|null $rank_key
 * @property-read int|null $position
 */
#[OrderedIn(Board::class)]
#[OrderedIn(Album::class)]
class Photo extends Model implements Ordered
{
    use IsOrdered;

    protected $guarded = [];

    /**
     * @return BelongsTo<Board, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * @return BelongsTo<Album, $this>
     */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }
}
