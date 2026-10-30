<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Pages;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Binders\Binder;

/**
 * @property int $id
 * @property int $binder_id
 * @property string|null $kind
 * @property string|null $rank_key
 * @property-read int|null $position
 */
#[OrderedIn(Binder::class)]
class Page extends Model implements Ordered
{
    use IsOrdered;

    protected $guarded = [];

    /**
     * @return BelongsTo<Binder, $this>
     */
    public function binder(): BelongsTo
    {
        return $this->belongsTo(Binder::class);
    }
}
