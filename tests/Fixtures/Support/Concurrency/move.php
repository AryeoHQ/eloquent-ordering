<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application;
use Support\Ordering\Exceptions\StaleOrder;
use Support\Ordering\Placement;
use Support\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Sections\Section;

require __DIR__.'/../../../../vendor/autoload.php';

[, $section, $asset, $expectedVersion] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    Section::query()->findOrFail($section)
        ->orderedCollection('assets')
        ->move(Asset::query()->findOrFail($asset), Placement::first(), (int) $expectedVersion);

    echo 'moved';
} catch (StaleOrder) {
    echo 'stale';
}
