<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Conferences;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;

/**
 * @property string $id
 * @property int $conference_id
 * @property int $speaker_id
 * @property string|null $rank_key
 * @property-read int|null $position
 */
#[OrderedIn(Conference::class)]
class ConferenceSpeaker extends Pivot implements Ordered
{
    use HasUuids;
    use IsOrdered;
    use SoftDeletes;

    protected $table = 'conference_speaker';

    /**
     * @return BelongsTo<Conference, $this>
     */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }
}
