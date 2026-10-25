<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Assets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Sections\Section;

/**
 * @property int $id
 * @property int $section_id
 * @property string|null $rank_key
 * @property string|null $title
 * @property bool $visible
 * @property-read int|null $position
 */
#[OrderedIn(Section::class)]
class Asset extends Model implements Ordered
{
    use IsOrdered;
    use SoftDeletes;

    public const string VISIBLE_SCOPE = 'visible';

    protected $guarded = [];

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(self::VISIBLE_SCOPE, static function (Builder $query): void {
            $query->where('visible', true);
        });
    }
}
