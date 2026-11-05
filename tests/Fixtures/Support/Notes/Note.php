<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Notes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Drawers\Drawer;

/**
 * @property int $id
 * @property int $drawer_id
 * @property string|null $rank_key
 * @property-read int|null $position
 */
#[OrderedIn(Drawer::class)]
class Note extends Model implements Ordered
{
    use IsOrdered;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return BelongsTo<Drawer, $this>
     */
    public function drawer(): BelongsTo
    {
        return $this->belongsTo(Drawer::class);
    }
}
