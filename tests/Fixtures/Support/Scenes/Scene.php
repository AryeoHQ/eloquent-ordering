<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Scenes;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Support\Ordering\Contracts\Ordered;
use Support\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Tours\Tour;

/**
 * @property string $id
 * @property string $tour_id
 * @property string $rank_key
 * @property-read int|null $position
 */
#[UseFactory(SceneFactory::class)]
class Scene extends Model implements Ordered
{
    /** @use HasFactory<SceneFactory> */
    use HasFactory;

    use HasUuids;
    use IsOrdered;

    protected $guarded = [];

    /**
     * @return BelongsTo<Tour, $this>
     */
    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }

    /**
     * @return BelongsTo<Tour, $this>
     */
    public function orderedParent(): BelongsTo
    {
        return $this->tour();
    }

    public function orderedCollectionName(): string
    {
        return 'scenes';
    }
}
