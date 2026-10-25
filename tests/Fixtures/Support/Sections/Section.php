<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Sections;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Videos\Video;

/**
 * @property int $id
 * @property string|null $name
 * @property int $concurrency_revision
 * @property int $assets_order_concurrency_revision
 * @property int $videos_order_concurrency_revision
 */
#[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')]
#[OrdersRelation(SectionRevision::VideosOrder, relation: 'videos')]
class Section extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $guarded = [];

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * @return HasMany<Video, $this>
     */
    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }
}
