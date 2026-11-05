<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Concurrency\Contracts\LocksParentsFirst;
use Support\Database\Eloquent\Ordering\Events\Reordered;
use Support\Database\Eloquent\Ordering\PositionChange;
use Support\Database\Eloquent\Ordering\Providers\Provider;

require __DIR__.'/../../../../vendor/autoload.php';

[, $class, $key, $order] = $_SERVER['argv'];
$report = ($_SERVER['argv'][4] ?? null) === 'report';

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

$reorders = [];

Event::listen(Reordered::class, static function (Reordered $event) use (&$reorders): void {
    $reorders[] = [
        $event->change->relation->parent::class,
        $event->change->relation->parent->getKey(),
        array_map(static fn (PositionChange $changed): array => [$changed->id, $changed->position, $changed->previousPosition], $event->change->changed),
    ];
});

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

    echo $report ? json_encode(['deleted', $reorders]) : 'deleted';
} catch (PDOException $exception) {
    echo str_contains($exception->getMessage(), 'SQLSTATE[40P01]') ? '40P01' : throw $exception;
}
