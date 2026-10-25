<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Clips;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Videos\Video;

/**
 * @property int $id
 * @property int $video_id
 * @property string|null $rank_key
 * @property string|null $title
 * @property-read int|null $position
 */
#[OrderedIn(Video::class)]
class Clip extends Model implements Ordered
{
    use IsOrdered;

    protected $guarded = [];

    /**
     * @return BelongsTo<Video, $this>
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
