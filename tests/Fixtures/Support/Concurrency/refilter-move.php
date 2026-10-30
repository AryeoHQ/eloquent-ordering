<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Binders\Binder;

require __DIR__.'/../../../../vendor/autoload.php';

[, $binder, $shelf, $kind] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    Binder::query()->findOrFail($binder)->update(['shelf_id' => intval($shelf), 'kind' => $kind]);

    echo 'saved';
} catch (PDOException $exception) {
    echo str_contains($exception->getMessage(), 'SQLSTATE[40P01]') ? '40P01' : throw $exception;
}
