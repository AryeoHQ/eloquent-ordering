<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Events\Reordered;
use Support\Database\Eloquent\Ordering\PositionChange;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Listings\ListingCustomer;

require __DIR__.'/../../../../vendor/autoload.php';

[, $pivot] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

$reorders = [];

Event::listen(Reordered::class, static function (Reordered $event) use (&$reorders): void {
    $reorders[] = [
        $event->change->order,
        array_map(static fn (PositionChange $changed): array => [$changed->id, $changed->position, $changed->previousPosition], $event->change->changed),
    ];
});

$restore = static function (Model $restored): void {
    assert(method_exists($restored, 'restore'));
    $restored->restore();
};

$restore(ListingCustomer::query()->withTrashed()->whereKey($pivot)->firstOrFail());

echo json_encode(['restored', $reorders, ListingCustomer::query()->findOrFail($pivot)->position]);
