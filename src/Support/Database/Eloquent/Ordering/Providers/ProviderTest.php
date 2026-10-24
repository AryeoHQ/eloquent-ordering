<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(Provider::class)]
class ProviderTest extends TestCase
{
    #[Test]
    public function it_boots_the_provider(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(Provider::class));
    }

    #[Test]
    public function it_loads_the_eloquent_concurrency_provider(): void
    {
        $this->assertTrue(Blueprint::hasMacro('concurrencyRevision'));
    }

    #[Test]
    public function it_runs_on_postgres(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    #[Test]
    public function it_runs_on_the_requested_postgres_version(): void
    {
        $requested = getenv('POSTGRES_VERSION');

        if ($requested === false || $requested === '') {
            $this->markTestSkipped('POSTGRES_VERSION is not set.');
        }

        $major = intdiv((int) DB::selectOne('show server_version_num')->server_version_num, 10000);

        $this->assertSame((int) $requested, $major);
    }
}
