<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Exceptions\UnavailableParent;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Drawers\Drawer;

require __DIR__.'/../../../../vendor/autoload.php';

[, $drawer] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    Drawer::query()->findOrFail($drawer)->notes()->create();

    echo 'created';
} catch (UnavailableParent $exception) {
    echo 'unavailable:'.$exception->reason->value;
}
