<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Slides;

use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\OrderedCollection;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Events\ModelUpdated;
use Tests\Fixtures\Support\Galleries\Gallery;

/**
 * @property string $id
 * @property string $slideable_id
 * @property string $slideable_type
 * @property string|null $rank_key
 * @property string|null $title
 * @property bool $visible
 * @property-read int|null $position
 */
#[CollectedBy(OrderedCollection::class)]
#[OrderedIn(Gallery::class)]
class Slide extends Model implements Ordered
{
    use HasUuids;
    use IsOrdered;
    use SoftDeletes;

    public const string MORPH_ALIAS = 'slide';

    public const string VISIBLE_SCOPE = 'visible';

    protected $guarded = [];

    protected $dispatchesEvents = ['updated' => ModelUpdated::class];

    /**
     * @return MorphTo<Model, $this>
     */
    public function slideable(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(self::VISIBLE_SCOPE, static function (Builder $query): void {
            $query->where($query->qualifyColumn('visible'), true);
        });
    }
}
