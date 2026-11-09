<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Documents;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Folders\EscapeStringFolder;

/**
 * @property int $id
 * @property int $folder_id
 * @property int|null $home_folder_id
 * @property string|null $rank_key
 * @property-read int|null $position
 */
#[OrderedIn(EscapeStringFolder::class)]
class EscapeStringDocument extends Model implements Ordered
{
    use IsOrdered;

    protected $table = 'documents';

    protected $guarded = [];
}
