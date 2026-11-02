<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Conferences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Speakers\Speaker;

/**
 * @property int $id
 * @property string $concurrency_revision
 */
class Conference extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $guarded = [];

    /**
     * @return BelongsToMany<Speaker, $this, ConferenceSpeaker>
     */
    #[OrdersRelation(ConferenceRevision::SpeakersOrder)]
    public function speakers(): BelongsToMany
    {
        return $this->belongsToMany(Speaker::class, 'conference_speaker')
            ->using(ConferenceSpeaker::class)
            ->wherePivotNull('deleted_at');
    }
}
