<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Sections;

use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum SectionRevision: string implements NamesConcurrencyRevision
{
    case AssetsOrder = 'assets_order';
    case FeaturedAssetsOrder = 'featured_assets_order';
    case VideosOrder = 'videos_order';
}
