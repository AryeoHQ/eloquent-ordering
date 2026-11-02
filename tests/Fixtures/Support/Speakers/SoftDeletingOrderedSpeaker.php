<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Speakers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;

abstract class SoftDeletingOrderedSpeaker extends Model implements Ordered
{
    use IsOrdered;
    use SoftDeletes;

    protected $table = 'speakers';

    protected $guarded = [];
}
