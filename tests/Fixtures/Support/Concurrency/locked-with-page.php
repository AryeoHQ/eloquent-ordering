<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Binders\Binder;
use Tests\Fixtures\Support\Pages\Page;
use Tests\Fixtures\Support\Shelves\Shelf;

require __DIR__.'/../../../../vendor/autoload.php';

[, $parent, $key, $page] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    DB::statement("set lock_timeout = '15s'");

    $members = DB::transaction(static function () use ($parent, $key, $page): array {
        $relation = $parent === 'shelf' ? Shelf::query()->findOrFail($key)->binders() : Binder::query()->findOrFail($key)->pages();

        return $relation->lockedMembers(Page::query()->findOrFail($page))->map(static fn (Model $member): mixed => $member->getKey())->all();
    });

    echo json_encode(['locked', $members]);
} catch (PDOException $exception) {
    echo preg_match('/SQLSTATE\[(40P01|55P03)\]/', $exception->getMessage(), $match) === 1 ? json_encode([$match[1]]) : throw $exception;
}
