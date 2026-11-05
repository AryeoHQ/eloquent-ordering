<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Drawers;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum DrawerRevision: string implements NamesConcurrencyRevision
{
    case NotesOrder = 'notes_order';
}
