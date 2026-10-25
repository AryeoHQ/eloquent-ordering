<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench;
use Support\Database\Eloquent\Ordering\Providers\Provider;

abstract class TestCase extends Testbench\TestCase
{
    use RefreshDatabase;

    protected $enablesPackageDiscoveries = true;

    protected function getPackageProviders($app): array
    {
        return [
            Provider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.sqlite.database', ':memory:');
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $this->app?->make('migrator')->run(__DIR__.'/Fixtures/Support/Migrations');
    }
}
