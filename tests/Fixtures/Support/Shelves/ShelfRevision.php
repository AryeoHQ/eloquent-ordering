<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Shelves;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum ShelfRevision: string implements NamesConcurrencyRevision
{
    case BindersOrder = 'binders_order';
}
