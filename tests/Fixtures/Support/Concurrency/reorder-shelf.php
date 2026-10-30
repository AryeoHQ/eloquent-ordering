<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Shelves\Shelf;
use Tests\Fixtures\Support\Shelves\ShelfRevision;

require __DIR__.'/../../../../vendor/autoload.php';

[, $shelf, $ids] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    Shelf::query()->findOrFail($shelf)->orderedRelation(ShelfRevision::BindersOrder)->reorder(array_map(intval(...), explode(',', $ids)), expected: null);

    echo 'reordered';
} catch (PDOException $exception) {
    echo str_contains($exception->getMessage(), 'SQLSTATE[40P01]') ? '40P01' : throw $exception;
}
