<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Speakers;

use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Attributes\OrderedThrough;
use Support\Database\Eloquent\Ordering\Provides\OrdersThroughPivots;
use Tests\Fixtures\Support\Conferences\Conference;
use Tests\Fixtures\Support\Panels\Panel;

/**
 * @property int $id
 * @property int $panel_id
 */
#[OrderedIn(Panel::class)]
#[OrderedThrough(Conference::class)]
class ReversedSpeaker extends SoftDeletingOrderedSpeaker
{
    use OrdersThroughPivots;
}
