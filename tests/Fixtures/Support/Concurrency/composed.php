<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Placement;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Composed\ComposedItem;

require __DIR__.'/../../../../vendor/autoload.php';

[, $action, $first, $second] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    DB::statement("set lock_timeout = '15s'");

    if ($action === 'move') {
        ComposedItem::query()->findOrFail($first)->move(Placement::last()->in(ComposedItem::query()->findOrFail($second)), expected: null);

        echo json_encode(['moved']);
    } else {
        $members = DB::transaction(static fn (): array => ComposedItem::query()->findOrFail($first)->children()->lockedMembers(ComposedItem::query()->findOrFail($second))->map(static fn (Model $member): mixed => $member->getKey())->all());

        echo json_encode(['locked', $members]);
    }
} catch (PDOException $exception) {
    echo preg_match('/SQLSTATE\[(40P01|55P03)\]/', $exception->getMessage(), $match) === 1 ? json_encode([$match[1]]) : throw $exception;
}
