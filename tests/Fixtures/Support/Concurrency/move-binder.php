<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Placement;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Binders\Binder;

require __DIR__.'/../../../../vendor/autoload.php';

[, $binder] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    Binder::query()->findOrFail($binder)->move(Placement::first(), expected: null);

    echo json_encode(['moved']);
} catch (PDOException $exception) {
    echo str_contains($exception->getMessage(), 'SQLSTATE[40P01]') ? json_encode(['40P01']) : throw $exception;
}
