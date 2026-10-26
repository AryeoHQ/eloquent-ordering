<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Boards;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum BoardRevision: string implements NamesConcurrencyRevision
{
    case PhotosOrder = 'photos_order';
}
