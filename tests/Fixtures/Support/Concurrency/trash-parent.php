<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Drawers\Drawer;

require __DIR__.'/../../../../vendor/autoload.php';

[, $drawer] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

Drawer::query()->findOrFail($drawer)->delete();

echo 'trashed';
