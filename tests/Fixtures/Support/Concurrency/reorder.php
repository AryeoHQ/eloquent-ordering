<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;

require __DIR__.'/../../../../vendor/autoload.php';

[, $section, $ids] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    Section::query()->findOrFail($section)->orderedRelation(SectionRevision::AssetsOrder)->reorder(array_map(intval(...), explode(',', $ids)), expected: null);

    echo 'reordered';
} catch (PDOException $exception) {
    echo str_contains($exception->getMessage(), 'SQLSTATE[40P01]') ? '40P01' : throw $exception;
}
