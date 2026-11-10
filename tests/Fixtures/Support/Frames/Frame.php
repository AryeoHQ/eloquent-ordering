<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Frames;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Reels\Reel;

/**
 * @property int $id
 * @property int $reel_id
 * @property string|null $rank_key
 * @property string $concurrency_revision
 * @property-read int|null $position
 */
#[OrderedIn(Reel::class)]
class Frame extends Model implements Ordered, Revisioned
{
    use HasConcurrencyRevisions;
    use IsOrdered;

    public const UPDATED_AT = 'modified_at';

    protected $guarded = [];

    /**
     * @return BelongsTo<Reel, $this>
     */
    public function reel(): BelongsTo
    {
        return $this->belongsTo(Reel::class);
    }
}
