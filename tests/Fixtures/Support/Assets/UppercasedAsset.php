<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Assets;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Sections\UppercasedSection;

/**
 * @property int $id
 * @property int $section_id
 * @property string|null $rank_key
 * @property string|null $title
 * @property-read int|null $position
 */
#[OrderedIn(UppercasedSection::class)]
class UppercasedAsset extends Model implements Ordered
{
    use IsOrdered;
    use SoftDeletes;

    protected $table = 'assets';

    protected $guarded = [];
}
