<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Videos;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum VideoRevision: string implements NamesConcurrencyRevision
{
    case ClipsOrder = 'clips_order';
}
