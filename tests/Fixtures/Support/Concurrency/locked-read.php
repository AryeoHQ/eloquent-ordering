<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Assets\Asset;

require __DIR__.'/../../../../vendor/autoload.php';

[, $asset, $read] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

echo json_encode(DB::transaction(static fn (): null|int => $read === 'refreshForUpdate()'
    ? Asset::query()->findOrFail($asset)->refreshForUpdate()->position
    : Asset::query()->lockForUpdate()->findOrFail($asset)->position));
