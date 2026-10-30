<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Schema;

use Illuminate\Database\Eloquent\Model;
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
use Tests\Fixtures\Support\Playlists\Playlist;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Shapes\UuidMorphManyShape;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Tours\TourRevision;
use Tests\Fixtures\Support\Tracks\Track;
use Tests\TestCase;

#[CoversClass(OrderedRelations::class)]
#[CoversClass(Macros::class)]
class OrderedRelationsTest extends TestCase
{
    private const string ASSETS_ORDER = 'assets_order_concurrency_revision';

    private const string SCENES_ORDER = 'scenes_order_concurrency_revision';

    private const string TRACKS_ORDER = 'tracks_order_concurrency_revision';

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
    public function it_installs_item_recorders_that_watch_the_rank_key_the_relation_and_the_soft_delete(string $table, array $columns): void
    {
        $sets = array_map(function (mixed $trigger): array {
            $this->assertIsObject($trigger);
            $this->assertIsString($trigger->arguments);

            $arguments = explode('\000', $trigger->arguments);
            $this->assertSame('item', $arguments[0]);

            $watched = json_decode($arguments[4] ?? '', true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($watched);
            sort($watched);

            return $watched;
        }, $this->triggers($table, ConcurrencyRevisions::NAMED_FUNCTION));

        $this->assertNotSame([], $sets);
        $this->assertSame([$columns], array_values(array_unique($sets, SORT_REGULAR)));
        $this->assertSame(count($sets), 3 * count($this->triggers($table, ConcurrencyRevisions::NAMED_CHECK_FUNCTION, deferred: true)));
    }

    #[Test]
    public function it_installs_an_item_recorder_that_watches_the_membership_columns(): void
    {
        $this->assertSame([['item', ['kind', 'playlist_id', 'rank_key']]], $this->recorders('tracks'));
    }

    #[Test]
    public function it_installs_a_parent_recorder_that_watches_the_membership_columns(): void
    {
        $this->assertSame([['parent', ['kind']]], $this->recorders('playlists'));
        $this->assertCount(1, $this->triggers('playlists', ConcurrencyRevisions::NAMED_CHECK_FUNCTION, deferred: true));
    }

    #[Test]
    public function it_installs_the_membership_columns_of_a_morph_item_recorder(): void
    {
        OrderedRelations::dropTrigger('scenes', TourRevision::ScenesOrder->value);
        OrderedRelations::createMorphTrigger('scenes', 'tours', 'sceneable', (new Tour)->getMorphClass(), TourRevision::ScenesOrder->value, membership: ['title']);

        $this->assertSame([['item', ['deleted_at', 'rank_key', 'sceneable_id', 'sceneable_type', 'title']]], $this->recorders('scenes'));
    }

    #[Test]
    public function it_refuses_the_commit_of_a_membership_change_that_no_order_revision_raise_follows(): void
    {
        $playlist = Playlist::create(['kind' => 'audio']);
        $track = new Track(['kind' => 'audio']);
        $track->playlist()->associate($playlist);
        $track->save();
        $itemChange = fn (): int => DB::table('tracks')->where('id', $track->id)->update(['kind' => 'video']);
        $parentChange = fn (): int => DB::table('playlists')->where('id', $playlist->id)->update(['kind' => 'video', 'concurrency_revision' => DB::raw('"concurrency_revision" + 1')]);

        $this->assertRefused($itemChange);
        $this->assertRefused($parentChange);
        $this->runDeferredChecks(function () use ($itemChange, $parentChange, $playlist): void {
            $itemChange();
            $this->raise($playlist, self::TRACKS_ORDER);
            $parentChange();
            $this->raise($playlist, self::TRACKS_ORDER);
        });

        $this->assertSame(4, $this->storedRevision($playlist, self::TRACKS_ORDER));
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
    public function it_refuses_the_commit_of_an_insert_that_no_order_revision_raise_follows(): void
    {
        $section = Section::create();
        $insert = fn (): bool => DB::table('assets')->insert([
            ['section_id' => $section->id, 'rank_key' => 'a0'],
            ['section_id' => $section->id, 'rank_key' => 'a1'],
        ]);

        $this->assertRefused($insert);
        $this->runDeferredChecks(function () use ($insert, $section): void {
            $insert();
            $this->raise($section, self::ASSETS_ORDER);
        });

        $this->assertSame(2, $this->storedRevision($section, self::ASSETS_ORDER));
    }

    #[Test]
    public function it_refuses_the_commit_of_a_rank_key_change_that_no_order_revision_raise_follows(): void
    {
        $section = Section::create();
        $asset = Asset::create(['section_id' => $section->id]);

        $this->assertRefused(fn (): int => DB::table('assets')->where('id', $asset->id)->update(['rank_key' => 'b0']));
    }

    #[Test]
    public function it_commits_a_change_to_an_unwatched_column_without_an_order_revision_raise(): void
    {
        $section = Section::create();
        $asset = Asset::create(['section_id' => $section->id]);

        $this->runDeferredChecks(fn (): int => DB::table('assets')->where('id', $asset->id)->update(['title' => 'Dusk']));

        $this->assertSame(2, $this->storedRevision($section, self::ASSETS_ORDER));
    }

    #[Test]
    public function it_refuses_the_commit_of_a_parent_change_unless_both_order_revisions_rise(): void
    {
        $source = Section::create();
        $destination = Section::create();
        $asset = Asset::create(['section_id' => $source->id]);
        $move = fn (): int => DB::table('assets')->where('id', $asset->id)->update(['section_id' => $destination->id]);

        $this->assertRefused(function () use ($move, $destination): void {
            $move();
            $this->raise($destination, self::ASSETS_ORDER);
        });
        $this->assertRefused(function () use ($move, $source): void {
            $move();
            $this->raise($source, self::ASSETS_ORDER);
        });
        $this->runDeferredChecks(function () use ($move, $source, $destination): void {
            $move();
            $this->raise($source, self::ASSETS_ORDER);
            $this->raise($destination, self::ASSETS_ORDER);
        });

        $this->assertSame([3, 2], [$this->storedRevision($source, self::ASSETS_ORDER), $this->storedRevision($destination, self::ASSETS_ORDER)]);
    }

    #[Test]
    public function it_refuses_the_commit_of_a_query_builder_soft_delete_that_no_order_revision_raise_follows(): void
    {
        $section = Section::create();
        $asset = Asset::create(['section_id' => $section->id]);

        $this->assertRefused(fn (): mixed => Asset::query()->whereKey($asset->id)->delete());
    }

    #[Test]
    public function it_refuses_the_commit_of_a_delete_that_no_order_revision_raise_follows(): void
    {
        $section = Section::create();
        $asset = Asset::create(['section_id' => $section->id]);

        $this->assertRefused(fn (): int => DB::table('assets')->where('id', $asset->id)->delete());
    }

    #[Test]
    public function it_drops_the_trigger(): void
    {
        $section = Section::create();

        OrderedRelations::dropTrigger('assets', SectionRevision::AssetsOrder->value);
        $this->runDeferredChecks(fn (): bool => DB::table('assets')->insert(['section_id' => $section->id, 'rank_key' => 'a0']));

        $this->assertSame([], $this->triggers('assets', ConcurrencyRevisions::NAMED_FUNCTION));
        $this->assertSame([], $this->triggers('assets', ConcurrencyRevisions::NAMED_CHECK_FUNCTION, deferred: true));
        $this->assertSame(1, $this->storedRevision($section, self::ASSETS_ORDER));
    }

    #[Test]
    public function it_records_only_the_morph_parent_whose_type_a_statement_names(): void
    {
        $tour = Tour::create();
        $listing = Listing::create(['id' => $tour->id]);
        $insert = fn (Model $parent): bool => DB::table('scenes')->insert([
            ['id' => Str::uuid7()->toString(), 'sceneable_type' => $parent->getMorphClass(), 'sceneable_id' => $parent->getKey(), 'rank_key' => 'a0'],
        ]);

        $this->assertRefused(function () use ($insert, $tour, $listing): void {
            $insert($tour);
            $this->raise($listing, self::SCENES_ORDER);
        });
        $this->runDeferredChecks(function () use ($insert, $tour): void {
            $insert($tour);
            $this->raise($tour, self::SCENES_ORDER);
        });
        $this->runDeferredChecks(function () use ($insert, $listing): void {
            $insert($listing);
            $this->raise($listing, self::SCENES_ORDER);
        });

        $this->assertSame([2, 2], [$this->storedRevision($tour, self::SCENES_ORDER), $this->storedRevision($listing, self::SCENES_ORDER)]);
    }

    #[Test]
    public function it_records_both_morph_parents_when_an_item_changes_parent_type(): void
    {
        $tour = Tour::create();
        $listing = Listing::create();
        $id = Str::uuid7()->toString();
        $this->runDeferredChecks(function () use ($id, $tour): void {
            DB::table('scenes')->insert(['id' => $id, 'sceneable_type' => $tour->getMorphClass(), 'sceneable_id' => $tour->id, 'rank_key' => 'a0']);
            $this->raise($tour, self::SCENES_ORDER);
        });
        $move = fn (): int => DB::table('scenes')->where('id', $id)->update(['sceneable_type' => $listing->getMorphClass(), 'sceneable_id' => $listing->id]);

        $this->assertRefused(function () use ($move, $listing): void {
            $move();
            $this->raise($listing, self::SCENES_ORDER);
        });
        $this->runDeferredChecks(function () use ($move, $tour, $listing): void {
            $move();
            $this->raise($tour, self::SCENES_ORDER);
            $this->raise($listing, self::SCENES_ORDER);
        });

        $this->assertSame([3, 2], [$this->storedRevision($tour, self::SCENES_ORDER), $this->storedRevision($listing, self::SCENES_ORDER)]);
    }

    #[Test]
    public function it_watches_the_rank_key_and_the_soft_delete_of_a_morph_item(): void
    {
        $tour = Tour::create();
        $id = Str::uuid7()->toString();
        $this->runDeferredChecks(function () use ($id, $tour): void {
            DB::table('scenes')->insert(['id' => $id, 'sceneable_type' => $tour->getMorphClass(), 'sceneable_id' => $tour->id, 'rank_key' => 'a0']);
            $this->raise($tour, self::SCENES_ORDER);
        });

        $this->assertRefused(fn (): int => DB::table('scenes')->where('id', $id)->update(['rank_key' => 'a1']));
        $this->assertRefused(fn (): int => DB::table('scenes')->where('id', $id)->update(['deleted_at' => now()]));
        $this->runDeferredChecks(fn (): int => DB::table('scenes')->where('id', $id)->update(['title' => 'Porch']));

        $this->assertSame(2, $this->storedRevision($tour, self::SCENES_ORDER));
    }

    #[Test]
    public function it_drops_the_morph_trigger_of_every_parent_type(): void
    {
        $tour = Tour::create();
        $listing = Listing::create();

        OrderedRelations::dropTrigger('scenes', TourRevision::ScenesOrder->value);
        $this->runDeferredChecks(fn (): bool => DB::table('scenes')->insert([
            ['id' => Str::uuid7()->toString(), 'sceneable_type' => $tour->getMorphClass(), 'sceneable_id' => $tour->id, 'rank_key' => 'a0'],
            ['id' => Str::uuid7()->toString(), 'sceneable_type' => $listing->getMorphClass(), 'sceneable_id' => $listing->id, 'rank_key' => 'a0'],
        ]));

        $this->assertSame(ListingRevision::ScenesOrder->value, TourRevision::ScenesOrder->value);
        $this->assertSame([], $this->triggers('scenes', ConcurrencyRevisions::NAMED_FUNCTION));
        $this->assertSame([1, 1], [$this->storedRevision($tour, self::SCENES_ORDER), $this->storedRevision($listing, self::SCENES_ORDER)]);
    }

    #[DataProvider('archetype')]
    #[Test]
    public function it_records_a_uuid_parent_only_for_a_row_that_names_it_by_its_morph_alias(UuidMorphManyShape $shape): void
    {
        $gallery = $shape->createParent();
        $slide = $shape->add($gallery);
        $insert = fn (string $type): bool => DB::table('slides')->insert(['id' => Str::uuid7()->toString(), 'slideable_type' => $type, 'slideable_id' => $gallery->id, 'rank_key' => 'a1']);

        $this->assertTrue(Relation::requiresMorphMap());
        $this->assertSame(Gallery::MORPH_ALIAS, DB::table('slides')->where('id', $slide->getKey())->value('slideable_type'));
        $this->assertSame(2, $shape->storedRevision($gallery));

        $this->runDeferredChecks(fn (): bool => $insert(Gallery::class));
        $this->assertRefused(fn (): bool => $insert(Gallery::MORPH_ALIAS));

        $this->assertSame(2, $shape->storedRevision($gallery));
    }

    /**
     * @return list<object>
     */
    private function triggers(string $table, string $function, bool $deferred = false): array
    {
        return DB::select(
            "select encode(t.tgargs, 'escape') as arguments from pg_trigger t join pg_proc p on p.oid = t.tgfoid where t.tgrelid = to_regclass(?) and p.proname = ? and t.tgdeferrable = ? and t.tginitdeferred = ?",
            [$table, $function, $deferred, $deferred],
        );
    }

    /**
     * @return list<array{string, list<string>}>
     */
    private function recorders(string $table): array
    {
        return array_map(function (mixed $trigger): array {
            $this->assertIsObject($trigger);
            $this->assertIsString($trigger->arguments);

            $arguments = explode('\000', $trigger->arguments);
            $watched = json_decode($arguments[4] ?? '', true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($watched);
            sort($watched);

            return [$arguments[0], array_values(array_filter($watched, is_string(...)))];
        }, array_values(array_unique($this->triggers($table, ConcurrencyRevisions::NAMED_FUNCTION), SORT_REGULAR)));
    }

    private function raise(Model $parent, string $column): void
    {
        DB::table($parent->getTable())->where('id', $parent->getKey())->increment($column);
    }

    private function storedRevision(Model $parent, string $column): int
    {
        return (int) DB::table($parent->getTable())->where('id', $parent->getKey())->value($column);
    }
}
