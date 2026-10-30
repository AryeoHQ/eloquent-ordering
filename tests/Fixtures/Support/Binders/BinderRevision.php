<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Binders;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum BinderRevision: string implements NamesConcurrencyRevision
{
    case PagesOrder = 'pages_order';
}
