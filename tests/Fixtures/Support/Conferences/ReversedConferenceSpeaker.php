<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Conferences;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;

#[OrderedIn(Conference::class)]
class ReversedConferenceSpeaker extends Pivot implements Ordered
{
    use HasUuids;
    use IsOrdered;
    use SoftDeletes;

    protected $table = 'conference_speaker';
}
