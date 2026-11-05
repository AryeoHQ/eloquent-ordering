<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Folders;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum FolderRevision: string implements NamesConcurrencyRevision
{
    case DocumentsOrder = 'documents_order';
}
