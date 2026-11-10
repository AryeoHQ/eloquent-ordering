<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Reels;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum ReelRevision: string implements NamesConcurrencyRevision
{
    case FramesOrder = 'frames_order';
}
