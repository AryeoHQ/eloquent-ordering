<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Galleries;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Events\ModelUpdated;
use Tests\Fixtures\Support\Slides\Slide;

/**
 * @property string $id
 * @property string|null $title
 * @property int $concurrency_revision
 * @property int $slides_order_concurrency_revision
 */
#[OrdersRelation(GalleryRevision::SlidesOrder, relation: 'slides')]
class Gallery extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;
    use HasUuids;

    public const string MORPH_ALIAS = 'gallery';

    protected $guarded = [];

    protected $dispatchesEvents = ['updated' => ModelUpdated::class];

    /**
     * @return MorphMany<Slide, $this>
     */
    public function slides(): MorphMany
    {
        return $this->morphMany(Slide::class, 'slideable');
    }
}
