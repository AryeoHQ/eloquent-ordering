<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Panels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Speakers\Speaker;

/**
 * @property int $id
 * @property int $concurrency_revision
 * @property int $speakers_order_concurrency_revision
 */
#[OrdersRelation(PanelRevision::SpeakersOrder, relation: 'speakers')]
class Panel extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $guarded = [];

    /**
     * @return HasMany<Speaker, $this>
     */
    public function speakers(): HasMany
    {
        return $this->hasMany(Speaker::class);
    }
}
