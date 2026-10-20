<?php

declare(strict_types=1);

namespace Support\Ordering\Schema;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(Macros::class)]
class MacrosTest extends TestCase
{
    #[Test]
    public function it_orders_rank_keys_by_byte(): void
    {
        Schema::create('ranked', function (Blueprint $table): void {
            $table->id();
            $table->rankKey();
        });

        $keys = ['a0', 'Zz', 'aZ', 'A1', 'b', 'a', '0z'];

        DB::table('ranked')->insert(array_map(fn (string $key): array => ['rank_key' => $key], $keys));

        sort($keys, SORT_STRING);

        $this->assertSame($keys, DB::table('ranked')->orderBy('rank_key')->pluck('rank_key')->all());
    }

    #[Test]
    public function it_names_the_rank_key_column(): void
    {
        Schema::create('ranked', function (Blueprint $table): void {
            $table->id();
            $table->rankKey('sort_key');
        });

        $this->assertSame(['id', 'sort_key'], Schema::getColumnListing('ranked'));
    }

    #[Test]
    public function it_collates_the_rank_key_by_byte_on_mysql(): void
    {
        $connection = new MySqlConnection(fn (): PDO => throw new LogicException('This suite has no MySQL server.'), 'testing', '', ['driver' => 'mysql']);
        $connection->useDefaultSchemaGrammar();

        $blueprint = new Blueprint($connection, 'ranked', function (Blueprint $table): void {
            $table->rankKey();
        });

        $this->assertSame(["alter table `ranked` add `rank_key` varchar(64) collate 'utf8mb4_bin' not null"], $blueprint->toSql());
    }

    #[Test]
    public function it_adds_an_order_version_counter_that_starts_at_zero(): void
    {
        Schema::create('places', function (Blueprint $table): void {
            $table->id();
            $table->orderVersion('subSections');
        });

        $id = DB::table('places')->insertGetId([]);

        $this->assertSame(0, (int) DB::table('places')->where('id', $id)->value('sub_sections_order_version'));
    }
}
