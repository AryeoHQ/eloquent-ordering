<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Schema;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
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

    #[Test]
    public function it_increments_the_order_revision_once_for_a_statement_that_inserts_items(): void
    {
        $section = Section::create();

        DB::table('assets')->insert([
            ['section_id' => $section->id, 'rank_key' => 'a0'],
            ['section_id' => $section->id, 'rank_key' => 'a1'],
            ['section_id' => $section->id, 'rank_key' => 'a2'],
        ]);

        $this->assertSame(2, $this->storedRevision($section));
    }

    #[Test]
    public function it_increments_the_order_revision_when_a_rank_key_changes(): void
    {
        $section = Section::create();
        $id = DB::table('assets')->insertGetId(['section_id' => $section->id, 'rank_key' => 'a0']);

        DB::table('assets')->where('id', $id)->update(['rank_key' => 'a1']);

        $this->assertSame(3, $this->storedRevision($section));
    }

    #[Test]
    public function it_keeps_the_order_revision_when_an_unwatched_column_changes(): void
    {
        $section = Section::create();
        $id = DB::table('assets')->insertGetId(['section_id' => $section->id, 'rank_key' => 'a0']);

        DB::table('assets')->where('id', $id)->update(['title' => 'Dusk']);

        $this->assertSame(2, $this->storedRevision($section));
    }

    #[Test]
    public function it_increments_both_order_revisions_when_an_item_changes_parent(): void
    {
        $source = Section::create();
        $destination = Section::create();
        $id = DB::table('assets')->insertGetId(['section_id' => $source->id, 'rank_key' => 'a0']);

        DB::table('assets')->where('id', $id)->update(['section_id' => $destination->id]);

        $this->assertSame(3, $this->storedRevision($source));
        $this->assertSame(2, $this->storedRevision($destination));
    }

    #[Test]
    public function it_increments_the_order_revision_when_a_query_builder_soft_deletes_an_item(): void
    {
        $section = Section::create();
        $asset = Asset::create(['section_id' => $section->id]);

        Asset::query()->whereKey($asset->id)->delete();

        $this->assertSame(3, $this->storedRevision($section));
        $this->assertSame($asset->rank_key, DB::table('assets')->where('id', $asset->id)->value('rank_key'));
        $this->assertSame([], $section->orderedRelation(SectionRevision::AssetsOrder)->query()->pluck('id')->all());
    }

    #[Test]
    public function it_increments_the_order_revision_when_an_item_is_deleted(): void
    {
        $section = Section::create();
        $id = DB::table('assets')->insertGetId(['section_id' => $section->id, 'rank_key' => 'a0']);

        DB::table('assets')->where('id', $id)->delete();

        $this->assertSame(3, $this->storedRevision($section));
    }

    #[Test]
    public function it_drops_the_trigger(): void
    {
        $section = Section::create();

        OrderedRelations::dropTrigger('assets', SectionRevision::AssetsOrder->value);
        DB::table('assets')->insert(['section_id' => $section->id, 'rank_key' => 'a0']);

        $this->assertSame(1, $this->storedRevision($section));
    }

    private function storedRevision(Section $section): int
    {
        return (int) DB::table('sections')->where('id', $section->id)->value('assets_order_concurrency_revision');
    }
}
