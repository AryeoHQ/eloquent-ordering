<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Assets\Asset;

require __DIR__.'/../../../../vendor/autoload.php';

[, $asset, $order] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    DB::transaction(static function () use ($asset, $order): void {
        $member = Asset::query()->findOrFail($asset);
        $parents = $order === 'parents first' ? $member->parentsToLockFirst() : [];
        usort($parents, static fn (Model $first, Model $second): int => [$first->getMorphClass(), $first->getKey()] <=> [$second->getMorphClass(), $second->getKey()]);

        foreach ([...$parents, $member] as $model) {
            $model->setRawAttributes($model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail()->getAttributes(), true);
        }

        $member->delete();
    });

    echo 'deleted';
} catch (PDOException $exception) {
    echo str_contains($exception->getMessage(), 'SQLSTATE[40P01]') ? '40P01' : throw $exception;
}
