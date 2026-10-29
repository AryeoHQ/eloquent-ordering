<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Playlists;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum PlaylistRevision: string implements NamesConcurrencyRevision
{
    case TracksOrder = 'tracks_order';
}
