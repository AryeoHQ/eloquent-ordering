<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Schema;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Support\Sections\Section;
use Tests\TestCase;

#[CoversClass(OrderedRelations::class)]
#[CoversClass(Macros::class)]
class OrderedRelationsTest extends TestCase
{
    #[Test]
    public function it_adds_a_nullable_byte_collated_rank_key(): void
    {
        $column = collect(Schema::getColumns('assets'))->firstWhere('name', 'rank_key');

        $this->assertIsArray($column);
        $this->assertSame('character varying(64)', $column['type']);
        $this->assertSame('C', $column['collation']);
        $this->assertTrue($column['nullable']);
    }

    #[Test]
    public function it_sorts_rank_keys_by_byte(): void
    {
        $section = Section::create();
        $keys = ['a0', 'Zz', 'aZ', 'A1', 'b', 'a', '0z'];

        DB::table('assets')->insert(array_map(fn (string $key): array => ['section_id' => $section->id, 'rank_key' => $key], $keys));

        sort($keys, SORT_STRING);

        $this->assertSame($keys, DB::table('assets')->orderBy('rank_key')->pluck('rank_key')->all());
    }
}
