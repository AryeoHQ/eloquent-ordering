<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application as Laravel;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Concurrency\Contracts\LocksParentsFirst;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Listings\Listing;

require __DIR__.'/../../../../vendor/autoload.php';

[, $class, $key, $order] = $_SERVER['argv'];

Application::create(
    resolvingCallback: static function (Laravel $app): void {
        $app->booting(static function (): void {
            config()->set('concurrency.models', [Listing::class]);
        });
    },
    options: ['extra' => ['providers' => [Provider::class]]],
);

try {
    DB::transaction(static function () use ($class, $key, $order): void {
        assert(is_a($class, Model::class, true));
        $model = $class::query()->findOrFail($key);
        $parents = $order === 'parents first' && $model instanceof LocksParentsFirst ? $model->parentsToLockFirst() : [];
        usort($parents, static fn (Model $first, Model $second): int => [$first->getMorphClass(), $first->getKey()] <=> [$second->getMorphClass(), $second->getKey()]);

        foreach ([...$parents, $model] as $locked) {
            $locked->setRawAttributes($locked->newQuery()->whereKey($locked->getKey())->lockForUpdate()->firstOrFail()->getAttributes(), true);
        }

        $model->delete();
    });

    echo 'deleted';
} catch (PDOException $exception) {
    echo str_contains($exception->getMessage(), 'SQLSTATE[40P01]') ? '40P01' : throw $exception;
}
