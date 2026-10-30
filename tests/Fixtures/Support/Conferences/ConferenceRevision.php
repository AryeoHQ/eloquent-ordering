<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Conferences;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum ConferenceRevision: string implements NamesConcurrencyRevision
{
    case SpeakersOrder = 'speakers_order';
}
