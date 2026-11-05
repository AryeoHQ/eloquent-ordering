<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Composed;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum ComposedItemRevision: string implements NamesConcurrencyRevision
{
    case ChildrenOrder = 'children_order';
}
