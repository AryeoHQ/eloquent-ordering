<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application;
use Support\Ordering\Exceptions\StaleOrder;
use Support\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Assets\Asset;

require __DIR__.'/../../../../vendor/autoload.php';

[, $asset] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    Asset::query()->findOrFail($asset)->delete();

    echo 'deleted';
} catch (StaleOrder) {
    echo 'stale';
}
