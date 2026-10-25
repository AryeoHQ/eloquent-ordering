<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Videos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Clips\Clip;
use Tests\Fixtures\Support\Sections\Section;

/**
 * @property int $id
 * @property int $section_id
 * @property string|null $rank_key
 * @property string|null $title
 * @property int $concurrency_revision
 * @property int $clips_order_concurrency_revision
 * @property-read int|null $position
 */
#[OrderedIn(Section::class)]
#[OrdersRelation(VideoRevision::ClipsOrder, relation: 'clips')]
class Video extends Model implements Ordered, OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;
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
     * @return HasMany<Clip, $this>
     */
    public function clips(): HasMany
    {
        return $this->hasMany(Clip::class);
    }
}
