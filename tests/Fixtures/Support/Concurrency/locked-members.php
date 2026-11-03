<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Exceptions\UnavailableParent;
use Support\Database\Eloquent\Ordering\Placement;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Drawers\Drawer;
use Tests\Fixtures\Support\Notes\Note;

require __DIR__.'/../../../../vendor/autoload.php';

[, $source, $destination] = $_SERVER['argv'];
$deletes = ($_SERVER['argv'][3] ?? null) === 'delete';

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

try {
    $moved = DB::transaction(static function () use ($source, $destination, $deletes): array {
        $from = Drawer::query()->findOrFail($source);
        $to = Drawer::query()->findOrFail($destination);
        $members = $from->notes()->lockedMembers($to);

        foreach ($members as $member) {
            assert($member instanceof Note);
            $member->move(Placement::last()->in($to), expected: null);
        }

        if ($deletes) {
            $from->delete();
        }

        return $members->map(static fn (Model $member): mixed => $member->getKey())->all();
    });

    echo json_encode(['moved', $moved]);
} catch (UnavailableParent $exception) {
    echo json_encode(['unavailable', $exception->reason->value]);
} catch (PDOException $exception) {
    echo str_contains($exception->getMessage(), 'SQLSTATE[40P01]') ? json_encode(['40P01']) : throw $exception;
}
