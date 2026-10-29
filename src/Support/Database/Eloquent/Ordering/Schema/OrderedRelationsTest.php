<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Schema;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Schema\ConcurrencyRevisions;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Galleries\Gallery;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingRevision;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Shapes\UuidMorphManyShape;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Tours\TourRevision;
use Tests\TestCase;

#[CoversClass(OrderedRelations::class)]
#[CoversClass(Macros::class)]
class OrderedRelationsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function watchedColumns(): iterable
    {
        yield 'a foreign key with soft deletes' => ['assets', ['deleted_at', 'rank_key', 'section_id']];
        yield 'a foreign key without soft deletes' => ['clips', ['rank_key', 'video_id']];
        yield 'a pivot' => ['listing_customer', ['deleted_at', 'listing_id', 'rank_key']];
        yield 'a morph of two parent types' => ['scenes', ['deleted_at', 'rank_key', 'sceneable_id', 'sceneable_type']];
        yield 'a morph pivot' => ['customerables', ['customerable_id', 'customerable_type', 'deleted_at', 'rank_key']];
        yield 'a uuid morph' => ['slides', ['deleted_at', 'rank_key', 'slideable_id', 'slideable_type']];
    }

    /**
     * @return iterable<string, array{UuidMorphManyShape}>
     */
    public static function archetype(): iterable
    {
        yield UuidMorphManyShape::NAME => [new UuidMorphManyShape];
    }

    /**
     * @param  list<string>  $columns
     */
    #[DataProvider('watchedColumns')]
    #[Test]
    public function it_compiles_triggers_that_watch_the_rank_key_the_relation_and_the_soft_delete(string $table, array $columns): void
    {
        $sets = array_map(function (mixed $trigger): array {
            $this->assertIsObject($trigger);
            $this->assertIsString($trigger->arguments);

            $watched = json_decode(explode('\000', $trigger->arguments)[3] ?? '', true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($watched);
            sort($watched);

            return $watched;
        }, DB::select(
            "select encode(t.tgargs, 'escape') as arguments from pg_trigger t join pg_proc p on p.oid = t.tgfoid where t.tgrelid = to_regclass(?) and p.proname = ?",
            [$table, ConcurrencyRevisions::NAMED_FUNCTION],
        ));

        $this->assertNotSame([], $sets);
        $this->assertSame([$columns], array_values(array_unique($sets, SORT_REGULAR)));
    }

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

    #[Test]
    public function it_increments_the_order_revision_of_the_morph_parent_whose_type_a_statement_names(): void
    {
        $tour = Tour::create();
        $listing = Listing::create(['id' => $tour->id]);

        DB::table('scenes')->insert([
            ['id' => Str::uuid7()->toString(), 'sceneable_type' => $tour->getMorphClass(), 'sceneable_id' => $tour->id, 'rank_key' => 'a0'],
            ['id' => Str::uuid7()->toString(), 'sceneable_type' => $tour->getMorphClass(), 'sceneable_id' => $tour->id, 'rank_key' => 'a1'],
        ]);

        $this->assertSame(2, $this->scenesRevision($tour));
        $this->assertSame(1, $this->scenesRevision($listing));

        DB::table('scenes')->insert(['id' => Str::uuid7()->toString(), 'sceneable_type' => $listing->getMorphClass(), 'sceneable_id' => $listing->id, 'rank_key' => 'a0']);

        $this->assertSame(2, $this->scenesRevision($tour));
        $this->assertSame(2, $this->scenesRevision($listing));
    }

    #[Test]
    public function it_increments_both_morph_parents_when_an_item_changes_parent_type(): void
    {
        $tour = Tour::create();
        $listing = Listing::create();
        $id = Str::uuid7()->toString();
        DB::table('scenes')->insert(['id' => $id, 'sceneable_type' => $tour->getMorphClass(), 'sceneable_id' => $tour->id, 'rank_key' => 'a0']);

        DB::table('scenes')->where('id', $id)->update(['sceneable_type' => $listing->getMorphClass(), 'sceneable_id' => $listing->id]);

        $this->assertSame(3, $this->scenesRevision($tour));
        $this->assertSame(2, $this->scenesRevision($listing));
    }

    #[Test]
    public function it_watches_the_rank_key_and_the_soft_delete_of_a_morph_item(): void
    {
        $tour = Tour::create();
        $id = Str::uuid7()->toString();
        DB::table('scenes')->insert(['id' => $id, 'sceneable_type' => $tour->getMorphClass(), 'sceneable_id' => $tour->id, 'rank_key' => 'a0']);

        DB::table('scenes')->where('id', $id)->update(['rank_key' => 'a1']);
        DB::table('scenes')->where('id', $id)->update(['title' => 'Porch']);
        DB::table('scenes')->where('id', $id)->update(['deleted_at' => now()]);

        $this->assertSame(4, $this->scenesRevision($tour));
    }

    #[Test]
    public function it_drops_the_morph_trigger_of_every_parent_type(): void
    {
        $tour = Tour::create();
        $listing = Listing::create();

        OrderedRelations::dropTrigger('scenes', TourRevision::ScenesOrder->value);
        DB::table('scenes')->insert([
            ['id' => Str::uuid7()->toString(), 'sceneable_type' => $tour->getMorphClass(), 'sceneable_id' => $tour->id, 'rank_key' => 'a0'],
            ['id' => Str::uuid7()->toString(), 'sceneable_type' => $listing->getMorphClass(), 'sceneable_id' => $listing->id, 'rank_key' => 'a0'],
        ]);

        $this->assertSame(ListingRevision::ScenesOrder->value, TourRevision::ScenesOrder->value);
        $this->assertSame(1, $this->scenesRevision($tour));
        $this->assertSame(1, $this->scenesRevision($listing));
    }

    #[DataProvider('archetype')]
    #[Test]
    public function it_increments_the_order_revision_of_a_uuid_parent_that_a_row_names_by_its_morph_alias(UuidMorphManyShape $shape): void
    {
        $gallery = $shape->createParent();
        $slide = $shape->add($gallery);

        $this->assertTrue(Relation::requiresMorphMap());
        $this->assertSame(Gallery::MORPH_ALIAS, DB::table('slides')->where('id', $slide->getKey())->value('slideable_type'));
        $this->assertSame(2, $shape->storedRevision($gallery));

        DB::table('slides')->insert(['id' => Str::uuid7()->toString(), 'slideable_type' => Gallery::class, 'slideable_id' => $gallery->id, 'rank_key' => 'a1']);

        $this->assertSame(2, $shape->storedRevision($gallery));

        DB::table('slides')->insert(['id' => Str::uuid7()->toString(), 'slideable_type' => Gallery::MORPH_ALIAS, 'slideable_id' => $gallery->id, 'rank_key' => 'a2']);

        $this->assertSame(3, $shape->storedRevision($gallery));
    }

    private function scenesRevision(Tour|Listing $parent): int
    {
        return (int) DB::table($parent->getTable())->where('id', $parent->id)->value('scenes_order_concurrency_revision');
    }

    private function storedRevision(Section $section): int
    {
        return (int) DB::table('sections')->where('id', $section->id)->value('assets_order_concurrency_revision');
    }
}
