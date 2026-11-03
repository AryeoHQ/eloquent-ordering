<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Exceptions\UnavailableParent;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Drawers\Drawer;
use Tests\Fixtures\Support\Notes\Note;

require __DIR__.'/../../../../vendor/autoload.php';

[, $destination, $ids] = $_SERVER['argv'];
$emptied = $ids === 'empty' ? $_SERVER['argv'][3] : null;

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

DB::statement("set lock_timeout = '5s'");

try {
    $moved = DB::transaction(static function () use ($destination, $ids, $emptied): array {
        $to = Drawer::query()->findOrFail($destination);
        $from = $emptied === null ? null : Drawer::query()->findOrFail($emptied);
        $members = $from === null
            ? Note::query()->findMany(explode(',', $ids))->sortBy(static fn (Note $note): int => array_search((string) $note->id, explode(',', $ids), true))->values()
            : $from->notes()->lockedMembers($to);

        $to->notes()->moveIn($members, expected: null);
        $from?->delete();

        return $members->map(static fn (Model $member): mixed => $member->getKey())->all();
    });

    echo json_encode(['moved', $moved]);
} catch (UnavailableParent $exception) {
    echo json_encode(['unavailable', $exception->reason]);
} catch (PDOException $exception) {
    echo preg_match('/SQLSTATE\[(40P01|55P03)\]/', $exception->getMessage(), $match) === 1 ? json_encode([$match[1]]) : throw $exception;
}
