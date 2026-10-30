<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Orchestra\Testbench;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Shapes\UuidMorphManyShape;

abstract class TestCase extends Testbench\TestCase
{
    use RefreshDatabase;

    protected $enablesPackageDiscoveries = true;

    protected function tearDown(): void
    {
        Relation::requireMorphMap(false);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            Provider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        Relation::morphMap(UuidMorphManyShape::morphMap());
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $this->app?->make('migrator')->run(__DIR__.'/Fixtures/Support/Migrations');
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    protected function runDeferredChecks(Closure $write): mixed
    {
        try {
            return DB::transaction(static function () use ($write): mixed {
                $result = $write();

                DB::statement('set constraints all immediate');

                return $result;
            });
        } finally {
            DB::statement('set constraints all deferred');
        }
    }

    /**
     * @param  Closure(): mixed  $write
     */
    protected function assertRefused(Closure $write): void
    {
        try {
            $this->runDeferredChecks($write);
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode(), $exception->getMessage());

            return;
        }

        $this->fail('The write was not refused.');
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{string, class-string, mixed, array<string, mixed>, array<string, mixed>}>
     */
    protected static function savedEvents(Model $model, array $before, array $after): array
    {
        $changed = array_flip(array_keys(array_filter($after, static fn (mixed $value, string $column): bool => ($before[$column] ?? null) !== $value, ARRAY_FILTER_USE_BOTH)));
        $written = array_diff_key($changed, [(string) $model->getUpdatedAtColumn() => true]);

        return [
            ['saving', $model::class, $model->getKey(), array_intersect_key($before, $written), array_intersect_key($after, $written)],
            ['updating', $model::class, $model->getKey(), array_intersect_key($before, $written), array_intersect_key($after, $written)],
            ['updated', $model::class, $model->getKey(), array_intersect_key($before, $changed), array_intersect_key($after, $changed)],
            ['saved', $model::class, $model->getKey(), array_intersect_key($before, $changed), array_intersect_key($after, $changed)],
        ];
    }

    /**
     * @return list<array{string, class-string, mixed, array<string, mixed>, array<string, mixed>}>
     */
    protected static function raisedEvents(Model $parent, string $column, int $from): array
    {
        $raised = [$column => sprintf('"%s" + 1', $column)];

        return array_map(
            static fn (string $event): array => [$event, $parent::class, $parent->getKey(), [$column => $from], $raised],
            ['saving', 'updating', 'updated', 'saved'],
        );
    }

    /**
     * @param  list<array{string, class-string, mixed, array<string, mixed>, array<string, mixed>}>  $fired
     * @return list<array{string, class-string, mixed, array<string, mixed>, array<string, mixed>}>
     */
    protected static function sqlOf(array $fired): array
    {
        $grammar = DB::connection()->getQueryGrammar();
        $sql = static fn (mixed $value): mixed => $value instanceof Expression ? $value->getValue($grammar) : $value;

        return array_map(static fn (array $event): array => [$event[0], $event[1], $event[2], array_map($sql, $event[3]), array_map($sql, $event[4])], $fired);
    }

    protected function racer(): Connection
    {
        config()->set('database.connections.racer', config('database.connections.pgsql'));
        config()->set('database.connections.observer', config('database.connections.pgsql'));

        return DB::connection('racer');
    }

    protected function startRival(string $fixture, string ...$arguments): InvokedProcess
    {
        return Process::env($this->databaseEnvironment())->start([
            PHP_BINARY,
            __DIR__.'/Fixtures/Support/Concurrency/'.$fixture,
            ...$arguments,
        ]);
    }

    protected function awaitLockWait(int $waiters, InvokedProcess ...$rivals): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            foreach ($rivals as $rival) {
                $this->assertTrue($rival->running(), $rival->errorOutput());
            }

            if ($this->lockWaits() >= $waiters) {
                return;
            }

            usleep(10_000);
        }

        $this->fail('The rivals never waited on the row locks.');
    }

    protected function awaitLockWaitOrExit(InvokedProcess $rival): bool
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            if ($this->lockWaits() >= 1) {
                return true;
            }

            if (! $rival->running()) {
                return false;
            }

            usleep(10_000);
        }

        $this->fail('The rival neither waited on a row lock nor finished.');
    }

    private function lockWaits(): int
    {
        return intval(DB::connection('observer')->scalar("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'"));
    }

    /**
     * @return array<string, string>
     */
    private function databaseEnvironment(): array
    {
        return [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => config()->string('database.connections.pgsql.host'),
            'DB_PORT' => config()->string('database.connections.pgsql.port'),
            'DB_DATABASE' => config()->string('database.connections.pgsql.database'),
            'DB_USERNAME' => config()->string('database.connections.pgsql.username'),
            'DB_PASSWORD' => config()->string('database.connections.pgsql.password'),
        ];
    }
}
