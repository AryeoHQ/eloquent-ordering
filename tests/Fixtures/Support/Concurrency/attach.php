<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Events\Reordered;
use Support\Database\Eloquent\Ordering\PositionChange;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Listings\Listing;

require __DIR__.'/../../../../vendor/autoload.php';

[, $listing, $customer] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

$reorders = [];

Event::listen(Reordered::class, static function (Reordered $event) use (&$reorders): void {
    $reorders[] = [
        $event->change->order,
        array_map(static fn (PositionChange $changed): array => [$changed->id, $changed->position, $changed->previousPosition], $event->change->changed),
        $event->change->revision,
    ];
});

try {
    Listing::query()->findOrFail($listing)->customers()->attach((int) $customer);

    echo json_encode(['attached', $reorders]);
} catch (PDOException $exception) {
    echo str_contains($exception->getMessage(), 'SQLSTATE[40P01]') ? json_encode(['40P01', []]) : throw $exception;
}
