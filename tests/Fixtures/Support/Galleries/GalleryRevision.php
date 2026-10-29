<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Galleries;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum GalleryRevision: string implements NamesConcurrencyRevision
{
    case SlidesOrder = 'slides_order';
}
