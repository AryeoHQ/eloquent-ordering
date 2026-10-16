<?php

declare(strict_types=1);

namespace Support\Ordering\Providers;

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
    public function it_connects_to_the_selected_database(): void
    {
        $driver = DB::connection()->getDriverName();

        $version = match ($driver) {
            'pgsql' => DB::selectOne('select version() as version')->version,
            default => DB::selectOne('select sqlite_version() as version')->version,
        };

        $this->assertSame(config('database.default'), $driver);
        $this->assertNotEmpty($version);

        if ($driver === 'pgsql') {
            $this->assertStringContainsString('PostgreSQL 16', $version);
        }
    }
}
