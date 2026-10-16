<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Assets;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
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
 * @property string|null $title
 * @property bool $visible
 * @property-read int|null $position
 */
#[ScopedBy(VisibleScope::class)]
#[UseFactory(AssetFactory::class)]
class Asset extends Model implements Ordered
{
    /** @use HasFactory<AssetFactory> */
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
        return 'assets';
    }
}
