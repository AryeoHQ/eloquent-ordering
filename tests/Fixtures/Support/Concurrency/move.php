<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Placement;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Sections\Section;

require __DIR__.'/../../../../vendor/autoload.php';

[, $asset, $destination, $expected] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    Asset::query()->findOrFail($asset)->move(Placement::first()->in(Section::query()->findOrFail($destination)), (int) $expected);

    echo 'moved';
} catch (StaleConcurrencyRevision) {
    echo 'stale';
}
