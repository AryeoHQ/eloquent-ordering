<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Placement;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Binders\Binder;

require __DIR__.'/../../../../vendor/autoload.php';

[, $binder] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    DB::statement("set lock_timeout = '15s'");

    Binder::query()->findOrFail($binder)->move(Placement::first(), expected: null);

    echo json_encode(['moved']);
} catch (PDOException $exception) {
    echo preg_match('/SQLSTATE\[(40P01|55P03)\]/', $exception->getMessage(), $match) === 1 ? json_encode([$match[1]]) : throw $exception;
}
