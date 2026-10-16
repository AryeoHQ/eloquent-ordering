<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Videos;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Support\Ordering\Contracts\Ordered;
use Support\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Sections\Section;

/**
 * @property int $id
 * @property int $section_id
 * @property string $rank_key
 * @property-read int|null $position
 */
#[UseFactory(VideoFactory::class)]
class Video extends Model implements Ordered
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    use IsOrdered;

    protected $guarded = [];

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function orderedParent(): BelongsTo
    {
        return $this->section();
    }

    public function orderedCollectionName(): string
    {
        return 'videos';
    }
}
