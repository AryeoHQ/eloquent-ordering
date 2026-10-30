<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Albums;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum AlbumRevision: string implements NamesConcurrencyRevision
{
    case PhotosOrder = 'photos_order';
}
