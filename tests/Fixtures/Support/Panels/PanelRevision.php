<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Panels;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum PanelRevision: string implements NamesConcurrencyRevision
{
    case SpeakersOrder = 'speakers_order';
}
