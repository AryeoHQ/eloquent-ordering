<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Assets;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Ordering\Contracts\Ordered;
use Support\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Sections\Section;

class TrashableAsset extends Model implements Ordered
{
    use IsOrdered;
    use SoftDeletes;

    protected $table = 'assets';

    /**
     * @return BelongsTo<Section, $this>
     */
    public function orderedParent(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function orderedCollectionName(): string
    {
        return 'assets';
    }
}
