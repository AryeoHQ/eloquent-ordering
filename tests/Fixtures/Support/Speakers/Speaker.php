<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Speakers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Support\Database\Eloquent\Ordering\Provides\OrdersThroughPivots;
use Tests\Fixtures\Support\Conferences\Conference;
use Tests\Fixtures\Support\Conferences\ConferenceSpeaker;
use Tests\Fixtures\Support\Panels\Panel;

/**
 * @property int $id
 * @property int $panel_id
 * @property string|null $name
 * @property string|null $rank_key
 * @property-read int|null $position
 */
#[OrderedIn(Panel::class)]
class Speaker extends Model implements Ordered
{
    use IsOrdered;
    use OrdersThroughPivots;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return BelongsTo<Panel, $this>
     */
    public function panel(): BelongsTo
    {
        return $this->belongsTo(Panel::class);
    }

    /**
     * @return BelongsToMany<Conference, $this, ConferenceSpeaker>
     */
    public function conferences(): BelongsToMany
    {
        return $this->belongsToMany(Conference::class, 'conference_speaker')
            ->using(ConferenceSpeaker::class)
            ->wherePivotNull('deleted_at');
    }
}
