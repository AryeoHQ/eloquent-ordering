<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Binders\Binder;

require __DIR__.'/../../../../vendor/autoload.php';

[, $binder] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    $pages = DB::transaction(static fn (): array => Binder::query()->findOrFail($binder)->pages()->lockedMembers()->map(static fn (Model $page): mixed => $page->getKey())->all());

    echo json_encode(['locked', $pages]);
} catch (PDOException $exception) {
    echo str_contains($exception->getMessage(), 'SQLSTATE[40P01]') ? json_encode(['40P01']) : throw $exception;
}
