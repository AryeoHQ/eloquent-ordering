<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Assets;

use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Sections\TouchedSection;

/**
 * @property int $id
 * @property int $section_id
 * @property string|null $rank_key
 * @property-read int|null $position
 */
#[OrderedIn(TouchedSection::class)]
#[Touches('section')]
class TouchingAsset extends Model implements Ordered
{
    use IsOrdered;
    use SoftDeletes;

    protected $table = 'assets';

    protected $guarded = [];

    /**
     * @return BelongsTo<TouchedSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(TouchedSection::class, 'section_id');
    }
}
