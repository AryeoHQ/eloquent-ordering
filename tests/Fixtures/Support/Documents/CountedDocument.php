<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Documents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Folders\CountedFolder;

/**
 * @property int $id
 * @property int $folder_id
 * @property string|null $rank_key
 * @property-read int $folder_count
 * @property-read int|null $position
 */
#[OrderedIn(CountedFolder::class)]
class CountedDocument extends Model implements Ordered
{
    use IsOrdered;

    protected $table = 'documents';

    protected $guarded = [];

    protected $withCount = ['folder'];

    /**
     * @return BelongsTo<CountedFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(CountedFolder::class);
    }
}
