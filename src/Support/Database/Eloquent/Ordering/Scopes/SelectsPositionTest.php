<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Scopes;

use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Scopes\SelectsConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Exceptions\UnselectedPosition;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Placement;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Binders\Binder;
use Tests\Fixtures\Support\Boards\Board;
use Tests\Fixtures\Support\Clips\Clip;
use Tests\Fixtures\Support\Composed\ComposedItem;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Documents\DatedDocument;
use Tests\Fixtures\Support\Documents\Document;
use Tests\Fixtures\Support\Documents\DocumentKind;
use Tests\Fixtures\Support\Documents\FiledDocument;
use Tests\Fixtures\Support\Documents\KindedDocument;
use Tests\Fixtures\Support\Documents\LocalizedDocument;
use Tests\Fixtures\Support\Folders\DatedFolder;
use Tests\Fixtures\Support\Folders\FiledFolder;
use Tests\Fixtures\Support\Folders\Folder;
use Tests\Fixtures\Support\Folders\KindedFolder;
use Tests\Fixtures\Support\Folders\LocalizedFolder;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Pages\Page;
use Tests\Fixtures\Support\Photos\Photo;
use Tests\Fixtures\Support\Playlists\Playlist;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Shelves\Shelf;
use Tests\Fixtures\Support\Tracks\Track;
use Tests\Fixtures\Support\Videos\Video;
use Tests\TestCase;

use function PHPStan\Testing\assertType;

#[CoversClass(SelectsPosition::class)]
class SelectsPositionTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function sizes(): iterable
    {
        foreach ([1, 5] as $parents) {
            foreach ([1, 10, 50] as $members) {
                yield "{$members} members in {$parents} parents" => [$parents, $members];
            }
        }
    }

    /**
     * @return iterable<string, array{Closure(Section, list<Asset>): iterable<mixed>, list<int>}>
     */
    public static function readers(): iterable
    {
        yield 'a filtered list' => [fn (Section $section, array $assets): iterable => Asset::query()->whereKey([$assets[1]->id, $assets[3]->id])->get(), [2, 4]];
        yield 'a list sorted by another column' => [fn (Section $section, array $assets): iterable => Asset::query()->where('section_id', $section->id)->orderByDesc('id')->get(), [4, 3, 2, 1]];
        yield 'a cursor page' => [fn (Section $section, array $assets): iterable => Asset::query()->where('section_id', $section->id)->orderBy('rank_key')->orderBy('id')->cursorPaginate(2, cursor: Asset::query()->where('section_id', $section->id)->orderBy('rank_key')->orderBy('id')->cursorPaginate(2)->nextCursor())->items(), [3, 4]];
        yield 'a page' => [fn (Section $section, array $assets): iterable => Asset::query()->where('section_id', $section->id)->orderBy('rank_key')->paginate(3, page: 2)->items(), [4]];
        yield 'route binding' => [fn (Section $section, array $assets): iterable => [(new Asset)->resolveRouteBinding($assets[2]->id)], [3]];
        yield 'an eager load' => [fn (Section $section, array $assets): iterable => Section::query()->with('assets')->findOrFail($section->id)->assets->sortBy('id'), [1, 2, 3, 4]];
        yield 'a lazy load' => [fn (Section $section, array $assets): iterable => Section::query()->findOrFail($section->id)->assets->sortBy('id'), [1, 2, 3, 4]];
        yield 'refresh()' => [fn (Section $section, array $assets): iterable => [Asset::query()->findOrFail($assets[1]->id)->refresh()], [2]];
        yield 'fresh()' => [fn (Section $section, array $assets): iterable => [$assets[1]->fresh()], [2]];
        yield 'refreshForUpdate()' => [fn (Section $section, array $assets): iterable => DB::transaction(fn (): array => [Asset::query()->findOrFail($assets[1]->id)->refreshForUpdate()]), [2]];
        yield 'lockForUpdate()->find()' => [fn (Section $section, array $assets): iterable => DB::transaction(fn (): array => [Asset::query()->lockForUpdate()->findOrFail($assets[1]->id)]), [2]];
        yield 'a shared lock' => [fn (Section $section, array $assets): iterable => DB::transaction(fn (): iterable => Asset::query()->where('section_id', $section->id)->sharedLock()->orderBy('id')->get()), [1, 2, 3, 4]];
        yield 'a fresh collection' => [fn (Section $section, array $assets): iterable => (new Collection([$assets[3], $assets[0]]))->fresh(), [4, 1]];
        yield 'a restored queued job' => [fn (Section $section, array $assets): iterable => self::restored($assets[2]), [3]];
        yield 'a read through an alias' => [fn (Section $section, array $assets): iterable => Asset::query()->withTrashed()->from('assets as a')->where('a.id', $assets[1]->id)->get(), [2]];
        yield 'a chunk' => [function (Section $section, array $assets): iterable {
            $read = [];
            Asset::query()->where('section_id', $section->id)->chunkById(3, function (Collection $chunk) use (&$read): void {
                array_push($read, ...$chunk->all());
            });

            return $read;
        }, [1, 2, 3, 4]];
    }

    /**
     * @return iterable<string, array{Closure(): Model}>
     */
    public static function itemsWithoutSoftDeletes(): iterable
    {
        yield 'video' => [function (): Model {
            Video::query()->create(['section_id' => Section::create()->id]);

            return Video::query()->create(['section_id' => Video::query()->firstOrFail()->section_id]);
        }];
        yield 'clip' => [function (): Model {
            $video = Video::query()->create(['section_id' => Section::create()->id]);
            Clip::query()->create(['video_id' => $video->id]);

            return Clip::query()->create(['video_id' => $video->id]);
        }];
        yield 'photo' => [function (): Model {
            $board = Board::create();
            Photo::query()->create(['board_id' => $board->id]);

            return Photo::query()->create(['board_id' => $board->id]);
        }];
        yield 'track' => [function (): Model {
            $playlist = Playlist::create(['kind' => 'audio']);
            Track::query()->create(['playlist_id' => $playlist->id, 'kind' => 'audio']);

            return Track::query()->create(['playlist_id' => $playlist->id, 'kind' => 'audio']);
        }];
        yield 'page' => [function (): Model {
            $binder = Binder::query()->create(['shelf_id' => Shelf::create()->id, 'kind' => 'notes']);
            Page::query()->create(['binder_id' => $binder->id, 'kind' => 'notes']);

            return Page::query()->create(['binder_id' => $binder->id, 'kind' => 'notes']);
        }];
        yield 'binder' => [function (): Model {
            $shelf = Shelf::create();
            Binder::query()->create(['shelf_id' => $shelf->id]);

            return Binder::query()->create(['shelf_id' => $shelf->id]);
        }];
    }

    /**
     * @return iterable<string, array{Closure(): Builder<Binder>}>
     */
    public static function binderQueries(): iterable
    {
        yield 'the declared order of the scopes' => [fn (): Builder => Binder::query()];
        yield 'the position after the digest' => [fn (): Builder => Binder::query()->withoutGlobalScope(SelectsPosition::class)->withGlobalScope(SelectsPosition::class, new SelectsPosition)];
        yield 'the digest after the position' => [fn (): Builder => Binder::query()->withoutGlobalScope(SelectsConcurrencyRevision::class)->withGlobalScope(SelectsConcurrencyRevision::class, new SelectsConcurrencyRevision)];
    }

    /**
     * @return iterable<string, array{Closure(Builder<Binder>, Binder): ?Model}>
     */
    public static function binderReads(): iterable
    {
        yield 'get()' => [fn (Builder $query, Binder $binder): null|Model => $query->whereKey($binder->id)->get()->all()[0] ?? null];
        yield 'find()' => [fn (Builder $query, Binder $binder): null|Model => $query->find($binder->id)];
        yield 'an aliased from' => [fn (Builder $query, Binder $binder): null|Model => $query->from('binders as b')->where('b.id', $binder->id)->first()];
    }

    /**
     * @return iterable<string, array{Closure(Binder): Binder}>
     */
    public static function binderReloads(): iterable
    {
        yield 'refresh()' => [fn (Binder $binder): Binder => $binder->refresh()];
        yield 'fresh()' => [fn (Binder $binder): Binder => $binder->fresh() ?? throw new LogicException('The binder is gone.')];
        yield 'refreshForUpdate()' => [fn (Binder $binder): Binder => DB::transaction(fn (): Binder => $binder->refreshForUpdate())];
    }

    /**
     * @return iterable<string, array{Closure(): mixed}>
     */
    public static function unqualifiedQueries(): iterable
    {
        yield 'withCount()' => [fn (): mixed => Section::query()->withCount('assets')->orderBy('id')->pluck('assets_count')->all()];
        yield 'withSum()' => [fn (): mixed => Section::query()->withSum('assets', 'id')->get()->all()];
        yield 'has() with a count' => [fn (): mixed => Section::query()->has('assets', '>=', 2)->get()->all()];
        yield 'has()' => [fn (): mixed => Section::query()->has('assets')->get()->all()];
        yield 'whereIn() with a subquery' => [fn (): mixed => Section::query()->whereIn('id', Asset::query()->select('section_id'))->get()->all()];
        yield 'groupBy()' => [fn (): mixed => Asset::query()->select('section_id')->groupBy('section_id')->get()->all()];
        yield 'distinct()->pluck()' => [fn (): mixed => Asset::query()->distinct()->pluck('section_id')->all()];
        yield 'count()' => [fn (): mixed => Asset::query()->count()];
        yield 'exists()' => [fn (): mixed => Asset::query()->exists()];
        yield 'a union' => [fn (): mixed => Asset::query()->where('title', 'first')->union(Asset::query()->where('title', 'second'))->get()->all()];
        yield 'a union of base queries' => [fn (): mixed => Asset::query()->where('title', 'first')->toBase()->union(Asset::query()->where('title', 'second')->toBase())->get()->all()];
        yield 'a union added after the scopes applied' => [fn (): mixed => Asset::query()->toBase()->union(DB::table('assets'))->get()->all()];
        yield 'a subquery from' => [fn (): mixed => Asset::query()->fromSub(Asset::query(), 'assets')->get()->all()];
    }

    #[DataProvider('sizes')]
    #[Test]
    public function it_reads_the_positions_of_a_page_in_one_query_whatever_its_size(int $parents, int $members): void
    {
        $sections = [];

        for ($index = 0; $index < $parents; $index++) {
            $section = Section::create();
            DB::table('assets')->insert(array_map(fn (string $key): array => ['section_id' => $section->id, 'rank_key' => $key], FractionalIndex::keysBetween(null, null, $members)));
            $sections[] = $section->id;
        }

        DB::enableQueryLog();

        $assets = Asset::query()->whereIn('section_id', $sections)->orderBy('section_id')->orderBy('rank_key')->get();
        $positions = $assets->map(fn (Asset $asset): null|int => $asset->position)->all();

        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame(array_merge(...array_fill(0, $parents, range(1, $members))), $positions);
    }

    /**
     * @param  Closure(Section, list<Asset>): iterable<mixed>  $read
     * @param  list<int>  $expected
     */
    #[DataProvider('readers')]
    #[Test]
    public function it_selects_the_position_for_every_way_a_caller_reads_an_item(Closure $read, array $expected): void
    {
        $section = Section::create();
        $assets = array_map(fn (): Asset => $this->asset($section), range(1, 4));
        $other = Section::create();
        $this->asset($other);

        $positions = [];

        foreach ($read($section, $assets) as $asset) {
            $this->assertInstanceOf(Asset::class, $asset);
            $this->assertArrayHasKey(OrderedRelation::POSITION, $asset->getAttributes());
            $positions[] = $asset->position;
        }

        $this->assertSame($expected, $positions);
    }

    #[Test]
    public function it_numbers_the_rows_of_every_parent_that_a_list_reads(): void
    {
        [$first, $second] = [Section::create(), Section::create()];
        $assets = [$this->asset($first), $this->asset($second), $this->asset($first), $this->asset($second), $this->asset($second)];

        $positions = Asset::query()->whereIn('section_id', [$first->id, $second->id])->orderBy('id')->get()->map(fn (Asset $asset): null|int => $asset->position)->all();

        $this->assertSame([1, 1, 2, 2, 3], $positions);
        $this->assertCount(5, $assets);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function positionQueryAliases(): iterable
    {
        yield 'the alias of the counted member' => ['ordering_member'];
        yield 'the alias of its parent' => ['ordering_parent'];
        yield 'the alias of its related row' => ['ordering_related'];
    }

    #[DataProvider('positionQueryAliases')]
    #[Test]
    public function it_numbers_the_rows_of_a_read_under_an_alias_that_the_position_query_also_uses(string $alias): void
    {
        [$first, $second] = [Section::create(), Section::create()];
        $assets = [$this->asset($first), $this->asset($first), $this->asset($second)];

        $positions = Asset::query()->withTrashed()->from("assets as {$alias}")
            ->whereIn("{$alias}.id", array_map(fn (Asset $asset): int => $asset->id, $assets))
            ->orderBy("{$alias}.id")
            ->get()
            ->map(fn (Asset $asset): null|int => $asset->position)
            ->all();

        $this->assertSame([1, 2, 1], $positions);
    }

    #[DataProvider('positionQueryAliases')]
    #[Test]
    public function it_numbers_the_pivots_of_a_join_under_an_alias_that_the_position_query_also_uses(string $alias): void
    {
        [$listing, $other] = [Listing::create(), Listing::create()];
        $customers = array_map(fn (): Customer => Customer::query()->create(), range(1, 3));
        $listing->customers()->attach(array_map(fn (Customer $customer): int => $customer->id, $customers));
        $other->customers()->attach($customers[2]->id);

        $read = Customer::query()
            ->join("listing_customer as {$alias}", "{$alias}.customer_id", '=', 'customers.id')
            ->whereIn("{$alias}.listing_id", [$listing->id, $other->id])
            ->select(['customers.*', "{$alias}.id as pivot_id"])
            ->orderBy("{$alias}.listing_id")
            ->orderBy("{$alias}.rank_key")
            ->get();

        $this->assertSame([1, 2, 3, 1], $read->map(fn (Customer $customer): mixed => $customer->getAttribute(SelectsPosition::PIVOT_ATTRIBUTE))->all());
    }

    #[Test]
    public function it_keeps_the_true_position_of_each_row_that_a_filter_on_a_member_column_keeps(): void
    {
        $section = Section::create();
        $shown = $this->asset($section);
        $hidden = [$this->asset($section, visible: false), $this->asset($section, visible: false)];
        $last = $this->asset($section);

        $visible = Asset::query()->where('section_id', $section->id)->orderBy('id')->get();
        $invisible = Asset::query()->withoutGlobalScope(Asset::VISIBLE_SCOPE)->where('visible', false)->orderBy('id')->get();

        $this->assertSame([$shown->id => 1, $last->id => 4], $visible->mapWithKeys(fn (Asset $asset): array => [$asset->id => $asset->position])->all());
        $this->assertSame([$hidden[0]->id => 2, $hidden[1]->id => 3], $invisible->mapWithKeys(fn (Asset $asset): array => [$asset->id => $asset->position])->all());
    }

    #[Test]
    public function it_pages_by_cursor_through_the_same_rows_with_and_without_positions(): void
    {
        $section = Section::create();
        array_map(fn (): Asset => $this->asset($section), range(1, 5));
        $page = fn (Builder $query): CursorPaginator => $query->where('section_id', $section->id)->orderBy('rank_key')->orderBy('id')->cursorPaginate(2);

        $with = $page(Asset::query());
        $without = $page(Asset::query()->withoutPositions());

        $this->assertCount(count($without->items()), $with->items());
        $this->assertSame($without->nextCursor()?->encode(), $with->nextCursor()?->encode());
        $this->assertSame([1, 2], array_map(fn (Model $asset): mixed => $asset->getAttribute('position'), $with->items()));
    }

    #[Test]
    public function it_reads_no_position_for_an_unsaved_item_without_a_query(): void
    {
        DB::enableQueryLog();

        $this->assertNull((new Asset)->position);
        $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function it_reads_no_position_for_a_trashed_item_or_an_item_without_a_rank_key_however_it_was_trashed(): void
    {
        $section = Section::create();
        [$first, $trashed, $bulkTrashed, $keyless, $last] = array_map(fn (): Asset => $this->asset($section), range(1, 5));
        $trashed->delete();
        Asset::query()->whereKey($bulkTrashed->id)->delete();
        DB::table('assets')->where('id', $keyless->id)->update([OrderedRelation::RANK_KEY => null]);

        $positions = Asset::query()->withTrashed()->where('section_id', $section->id)->orderBy('id')->get()->map(fn (Asset $asset): null|int => $asset->position)->all();

        $this->assertNotNull(DB::table('assets')->where('id', $bulkTrashed->id)->value(OrderedRelation::RANK_KEY));
        $this->assertSame([1, null, null, null, 2], $positions);
        $this->assertSame([1, 2], [$first->fresh()?->position, $last->fresh()?->position]);
    }

    #[Test]
    public function it_reads_no_position_for_a_row_whose_parent_is_trashed_or_missing(): void
    {
        [$live, $trashed] = [$this->root(), $this->root()];
        $children = [$this->child($live), $this->child($trashed), $this->child($live)];
        $trashed->delete();
        $orphan = $this->child($live);
        DB::table('composed_items')->where('id', $orphan->id)->update(['parent_id' => '00000000-0000-4000-8000-000000000000']);

        $positions = ComposedItem::query()->whereKey([...array_map(fn (ComposedItem $child): string => $child->id, $children), $orphan->id])->get()
            ->mapWithKeys(fn (ComposedItem $child): array => [$child->id => $child->position])->all();

        $this->assertSame([1, null, 2, null], [$positions[$children[0]->id], $positions[$children[1]->id], $positions[$children[2]->id], $positions[$orphan->id]]);
    }

    #[Test]
    public function it_reads_no_position_for_a_row_that_a_declared_filter_excludes(): void
    {
        $parent = $this->root();
        $first = $this->child($parent);
        $hidden = $this->child($parent, hidden: true);
        $last = $this->child($parent);

        $positions = ComposedItem::query()->where('parent_id', $parent->id)->get()->mapWithKeys(fn (ComposedItem $child): array => [$child->id => $child->position])->all();

        $this->assertSame([1, null, 2], [$positions[$first->id], $positions[$hidden->id], $positions[$last->id]]);
    }

    #[Test]
    public function it_numbers_each_track_among_the_tracks_whose_kind_its_playlist_names(): void
    {
        $tracks = [...$this->playlist('audio', ['audio', 'video', 'audio']), ...$this->playlist('video', ['video', 'audio', 'video']), ...$this->playlist(null, [null, 'audio', null])];

        $positions = Track::query()->whereKey(array_map(fn (Track $track): int => $track->id, $tracks))->orderBy('id')->get()->map(fn (Track $track): null|int => $track->position)->all();

        $this->assertSame([1, null, 2, 1, null, 2, 1, null, 2], $positions);
    }

    #[Test]
    public function it_reads_no_position_for_a_page_whose_kind_stopped_matching_its_binder(): void
    {
        $binder = Binder::query()->create(['shelf_id' => Shelf::create()->id, 'kind' => 'notes']);
        [$first, $changed, $last] = array_map(fn (): Page => Page::query()->create(['binder_id' => $binder->id, 'kind' => 'notes']), range(1, 3));
        DB::table('pages')->where('id', $changed->id)->update(['kind' => 'drafts']);

        $positions = Page::query()->where('binder_id', $binder->id)->orderBy('id')->get()->map(fn (Page $page): null|int => $page->position)->all();

        $this->assertSame([1, null, 2], $positions);
        $this->assertCount(3, [$first, $changed, $last]);
    }

    #[Test]
    public function it_refuses_to_read_a_position_that_the_query_did_not_select(): void
    {
        $asset = $this->asset(Section::create());

        foreach ([
            'withoutPositions()' => fn (): Model => Asset::query()->withoutPositions()->findOrFail($asset->id),
            'an explicit select list' => fn (): Model => Asset::query()->select(['id', 'section_id'])->findOrFail($asset->id),
            'explicit columns' => fn (): Model => Asset::query()->findOrFail($asset->id, ['id', 'section_id']),
            'withCount()' => fn (): Model => Asset::query()->withCount('section')->findOrFail($asset->id),
        ] as $case => $read) {
            try {
                $read()->getAttribute('position');
                $this->fail("{$case} read a position it never selected.");
            } catch (UnselectedPosition $exception) {
                $this->assertSame($asset->id, $exception->item->getKey(), $case);
            }
        }
    }

    /**
     * @param  Closure(): mixed  $query
     */
    #[DataProvider('unqualifiedQueries')]
    #[Test]
    public function it_leaves_a_query_that_cannot_carry_a_position_working(Closure $query): void
    {
        $section = Section::create();
        $this->asset($section, title: 'first');
        $this->asset($section, title: 'second');
        $this->asset(Section::create(), title: 'first');

        $this->assertNotNull($query());
    }

    #[Test]
    public function it_selects_no_position_into_either_side_of_a_union(): void
    {
        $section = Section::create();
        $this->asset($section, title: 'first');
        $this->asset($section, title: 'second');

        $assets = Asset::query()->where('title', 'first')->union(Asset::query()->where('title', 'second'))->get();

        $this->assertCount(2, $assets);
        $this->assertSame([false, false], $assets->map(fn (Asset $asset): bool => array_key_exists(OrderedRelation::POSITION, $asset->getAttributes()))->all());
    }

    #[Test]
    public function it_selects_no_position_into_any_operand_of_a_nested_union(): void
    {
        $section = Section::create();
        $this->asset($section, title: 'first');
        $this->asset($section, title: 'second');
        $this->asset($section, title: 'third');

        $assets = Asset::query()->where('title', 'first')->union(Asset::query()->where('title', 'second')->union(Asset::query()->where('title', 'third')))->get();

        $this->assertCount(3, $assets);
        $this->assertSame([false, false, false], $assets->map(fn (Asset $asset): bool => array_key_exists(OrderedRelation::POSITION, $asset->getAttributes()))->all());
    }

    /**
     * @param  Closure(): Model  $create
     */
    #[DataProvider('itemsWithoutSoftDeletes')]
    #[Test]
    public function it_reads_the_position_of_an_item_without_soft_deletes(Closure $create): void
    {
        $item = $create();

        $read = $item->newQuery()->findOrFail($item->getKey());

        $this->assertSame(2, $read->getAttribute('position'));
        $this->assertSame(2, $item->getAttribute('position'));
    }

    /**
     * @param  Closure(): Builder<Binder>  $query
     */
    #[DataProvider('binderQueries')]
    #[Test]
    public function it_selects_the_own_digest_of_a_revisioned_item_beside_its_position(Closure $query): void
    {
        $shelf = Shelf::create();
        Binder::query()->create(['shelf_id' => $shelf->id]);
        $binder = Binder::query()->create(['shelf_id' => $shelf->id]);

        foreach (self::binderReads() as $case => [$reader]) {
            $loaded = $reader($query(), $binder);

            $this->assertInstanceOf(Binder::class, $loaded, $case);
            $this->assertSame(2, $loaded->position, $case);
            $this->assertSame($binder->concurrencyRevision(), $loaded->concurrencyRevision(), $case);
        }
    }

    /**
     * @param  Closure(Binder): Binder  $reload
     */
    #[DataProvider('binderReloads')]
    #[Test]
    public function it_reloads_the_position_and_the_own_digest_of_a_revisioned_item(Closure $reload): void
    {
        $shelf = Shelf::create();
        Binder::query()->create(['shelf_id' => $shelf->id]);
        $binder = Binder::query()->create(['shelf_id' => $shelf->id]);
        $stale = Binder::query()->findOrFail($binder->id);
        $binder->move(Placement::first(), expected: null);
        $binder->update(['kind' => 'notes']);
        $this->assertNotSame($stale->concurrencyRevision(), $binder->concurrencyRevision());

        $reloaded = $reload($stale);

        $this->assertSame(1, $reloaded->position);
        $this->assertSame($binder->concurrencyRevision(), $reloaded->concurrencyRevision());
    }

    #[Test]
    public function it_selects_the_position_of_a_model_that_composes_its_own_key_query_with_another_trait(): void
    {
        $parent = $this->root();
        $first = $this->child($parent);
        $second = $this->child($parent);
        $first->move(Placement::after($second->id), expected: null);

        $this->assertSame(2, $first->position);
        $this->assertSame([2, 1], [$first->fresh()?->position, $second->refresh()->position]);
        $this->assertSame(2, ComposedItem::query()->findOrFail($first->id)->position);
        $this->assertIsString(ComposedItem::query()->findOrFail($first->id)->concurrencyRevision());
    }

    #[Test]
    public function it_renders_the_positions_a_page_read_after_another_session_deletes_and_moves_its_rows(): void
    {
        $racer = $this->racer();
        config()->set('database.connections.mover', config('database.connections.pgsql'));
        [$section, $assets] = $this->racingSectionWith(4);
        [$destination] = $this->racingSectionWith(0);
        $written = false;

        try {
            DB::listen(function (QueryExecuted $query) use (&$written, $assets, $destination): void {
                if ($written || $query->connectionName !== 'pgsql' || ! str_contains($query->sql, 'as "position" from "assets"')) {
                    return;
                }

                $written = true;

                Asset::on('mover')->findOrFail($assets[1]->id)->delete();
                Asset::on('mover')->findOrFail($assets[2]->id)->move(Placement::first()->in(Section::on('mover')->findOrFail($destination->id)), expected: null);
            });

            $page = Asset::query()->where('section_id', $section->id)->orderBy('id')->get();
            DB::enableQueryLog();
            $rendered = $page->map(fn (Asset $asset): null|int => $asset->position)->all();

            $this->assertTrue($written, 'No session wrote between the read and the render.');
            $this->assertSame([], DB::getQueryLog());
            $this->assertSame([1, 2, 3, 4], $rendered);
            $this->assertSame([1, 2], Asset::query()->where('section_id', $section->id)->orderBy('id')->get()->map(fn (Asset $asset): null|int => $asset->position)->all());
            $this->assertSame(1, Asset::query()->findOrFail($assets[2]->id)->position);
        } finally {
            $this->finishRace($racer, $section, $destination);
        }
    }

    #[Test]
    public function it_renders_the_positions_a_page_read_in_another_session_before_a_delete_and_a_move_committed(): void
    {
        $racer = $this->racer();
        [$section, $assets] = $this->racingSectionWith(4);
        [$destination] = $this->racingSectionWith(0);
        $lock = random_int(1, PHP_INT_MAX);
        $reader = null;

        try {
            $racer->select('select pg_advisory_lock(?)', [$lock]);
            $reader = $this->startRival('read.php', (string) $section->id, (string) $lock);
            $this->awaitLockWait(1, $reader);

            Asset::on('racer')->findOrFail($assets[1]->id)->delete();
            Asset::on('racer')->findOrFail($assets[2]->id)->move(Placement::first()->in(Section::on('racer')->findOrFail($destination->id)), expected: null);
            $racer->select('select pg_advisory_unlock(?)', [$lock]);

            $result = $reader->wait();
            $rendered = json_decode($result->output(), true);

            $this->assertSame(array_map(fn (Asset $asset, int $index): array => [$asset->id, $index + 1], $assets, array_keys($assets)), $rendered, $result->errorOutput());
        } finally {
            $racer->select('select pg_advisory_unlock_all()');
            $reader?->wait();
            $this->finishRace($racer, $section, $destination);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lockingReads(): iterable
    {
        yield 'lockForUpdate()->find()' => ['lockForUpdate()->find()'];
        yield 'refreshForUpdate()' => ['refreshForUpdate()'];
    }

    #[DataProvider('lockingReads')]
    #[Test]
    public function it_reads_the_position_that_a_move_committed_while_a_locking_read_waited_on_the_row(string $read): void
    {
        $racer = $this->racer();
        [$section, $assets] = $this->racingSectionWith(4);
        $reader = null;

        try {
            $racer->beginTransaction();
            Asset::on('racer')->findOrFail($assets[2]->id)->move(Placement::first(), expected: null);
            $reader = $this->startRival('locked-read.php', (string) $assets[2]->id, $read);
            $this->awaitLockWait(1, $reader);
            $racer->commit();

            $result = $reader->wait();

            $this->assertSame(1, json_decode($result->output(), true), $result->errorOutput());
        } finally {
            if ($racer->transactionLevel() > 0) {
                $racer->rollBack();
            }

            $reader?->wait();
            $this->finishRace($racer, $section);
        }
    }

    /**
     * @return iterable<string, array{Closure(Asset): Asset}>
     */
    public static function lockingReadsOutsideATransaction(): iterable
    {
        yield 'lockForUpdate()->find()' => [fn (Asset $asset): Asset => Asset::on('racer')->lockForUpdate()->findOrFail($asset->id)];
        yield 'a shared lock' => [fn (Asset $asset): Asset => Asset::on('racer')->sharedLock()->findOrFail($asset->id)];
        yield 'refreshForUpdate()' => [fn (Asset $asset): Asset => Asset::on('racer')->findOrFail($asset->id)->refreshForUpdate()];
    }

    /**
     * @param  Closure(Asset): Asset  $read
     */
    #[DataProvider('lockingReadsOutsideATransaction')]
    #[Test]
    public function it_selects_no_position_for_a_locking_read_that_no_transaction_holds(Closure $read): void
    {
        $racer = $this->racer();
        [$section, $assets] = $this->racingSectionWith(2);

        try {
            $read($assets[1])->getAttribute('position');
            $this->fail('A locking read outside a transaction read a position.');
        } catch (UnselectedPosition $exception) {
            $this->assertSame(0, $racer->transactionLevel());
            $this->assertStringContainsString('A locking read outside a transaction selects none', $exception->getMessage());
        } finally {
            $this->finishRace($racer, $section);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locksThatBlockEveryUpdate(): iterable
    {
        yield 'for update' => ['for update'];
        yield 'for no key update' => ['for no key update'];
        yield 'for share' => ['for share'];
        yield 'for update nowait' => ['for update nowait'];
        yield 'for share skip locked, in capitals' => ['FOR SHARE SKIP LOCKED'];
    }

    #[DataProvider('locksThatBlockEveryUpdate')]
    #[Test]
    public function it_reads_the_position_of_a_locking_read_whose_lock_blocks_every_update_of_the_row(string $lock): void
    {
        $section = Section::create();
        $assets = [$this->asset($section), $this->asset($section)];

        $read = DB::transaction(fn (): Asset => Asset::query()->lock($lock)->findOrFail($assets[1]->id));

        $this->assertSame(2, $read->position);
    }

    /**
     * @return iterable<string, array{Closure(Asset): Asset}>
     */
    public static function locksThatLetAnUpdateThrough(): iterable
    {
        yield 'for key share' => [fn (Asset $asset): Asset => Asset::query()->lock('for key share')->findOrFail($asset->id)];
        yield 'for key share nowait' => [fn (Asset $asset): Asset => Asset::query()->lock('for key share nowait')->findOrFail($asset->id)];
        yield 'a lock on another table' => [fn (Asset $asset): Asset => Asset::query()
            ->join('sections', 'sections.id', '=', 'assets.section_id')
            ->where('assets.id', $asset->id)
            ->lock('for update of sections')
            ->firstOrFail()];
    }

    /**
     * @param  Closure(Asset): Asset  $read
     */
    #[DataProvider('locksThatLetAnUpdateThrough')]
    #[Test]
    public function it_selects_no_position_for_a_locking_read_whose_lock_lets_another_transaction_update_the_row(Closure $read): void
    {
        $section = Section::create();
        $assets = [$this->asset($section), $this->asset($section)];

        try {
            DB::transaction(fn (): mixed => $read($assets[1])->getAttribute('position'));
            $this->fail('A locking read whose lock lets an update through read a position.');
        } catch (UnselectedPosition $exception) {
            $this->assertStringContainsString('whose lock lets another transaction update the row', $exception->getMessage());
        }
    }

    #[Test]
    public function it_reads_the_own_columns_and_the_position_of_a_locking_read_that_joins_another_table(): void
    {
        $section = Section::create();
        $assets = [$this->asset($section), $this->asset($section)];

        $read = DB::transaction(fn (): Collection => Asset::query()
            ->join('sections', 'sections.id', '=', 'assets.section_id')
            ->where('assets.section_id', $section->id)
            ->orderBy('assets.id')
            ->lockForUpdate()
            ->get());

        $this->assertSame([[$assets[0]->id, 1], [$assets[1]->id, 2]], $read->map(fn (Asset $asset): array => [$asset->id, $asset->position])->all());
    }

    #[Test]
    public function it_numbers_the_members_of_a_parent_that_casts_its_paired_column_to_an_enum(): void
    {
        $folder = KindedFolder::query()->create(['kind' => DocumentKind::Notes]);
        $created = array_map(fn (DocumentKind $kind): KindedDocument => KindedDocument::query()->create(['folder_id' => $folder->id, 'kind' => $kind->value]), [DocumentKind::Notes, DocumentKind::Drafts, DocumentKind::Notes]);

        $read = KindedDocument::query()->where('folder_id', $folder->id)->orderBy('id')->get();

        $this->assertSame([1, null, 2], $read->map(fn (KindedDocument $document): null|int => $document->position)->all());
        $this->assertSame([1, null, 2], array_map(fn (KindedDocument $document): null|int => $document->position, $created));
    }

    #[Test]
    public function it_numbers_the_members_that_a_runtime_filter_value_keeps_when_each_query_runs(): void
    {
        $folder = LocalizedFolder::query()->create();

        foreach (['en', 'fr', 'en', 'fr'] as $locale) {
            LocalizedDocument::query()->create(['folder_id' => $folder->id, 'locale' => $locale]);
        }

        $read = fn (): array => LocalizedDocument::query()->where('folder_id', $folder->id)->orderBy('id')->get()->map(fn (LocalizedDocument $document): null|int => $document->position)->all();

        app()->setLocale('en');
        $english = $read();
        app()->setLocale('fr');
        $french = $read();

        $this->assertSame([1, null, 2, null], $english);
        $this->assertSame([null, 1, null, 2], $french);
    }

    #[Test]
    public function it_numbers_the_members_that_a_filter_on_the_key_of_their_parent_keeps(): void
    {
        [$first, $second] = [Folder::query()->create(), Folder::query()->create()];
        $documents = array_map(fn (array $folders): Document => Document::query()->create(['folder_id' => $folders[0]->id, 'home_folder_id' => $folders[1]->id]), [
            [$first, $first],
            [$first, $second],
            [$first, $first],
            [$second, $second],
        ]);

        $positions = Document::query()->whereKey(array_map(fn (Document $document): int => $document->id, $documents))->orderBy('id')->get()->map(fn (Document $document): null|int => $document->position)->all();

        $this->assertSame([1, null, 2, 1], $positions);
    }

    #[Test]
    public function it_numbers_the_members_that_a_filter_on_a_date_constant_keeps(): void
    {
        $folder = DatedFolder::query()->create();

        foreach (['2025-06-01 00:00:00', '2026-06-01 00:00:00', null, DatedFolder::PUBLISHED_BY] as $published) {
            DatedDocument::query()->create(['folder_id' => $folder->id, 'published_at' => $published]);
        }

        $positions = DatedDocument::query()->where('folder_id', $folder->id)->orderBy('id')->get()->map(fn (DatedDocument $document): null|int => $document->position)->all();

        $this->assertSame([1, null, null, 2], $positions);
    }

    #[Test]
    public function it_numbers_the_members_that_a_filter_on_a_bound_subquery_keeps(): void
    {
        [$archive, $other, $folder] = [FiledFolder::query()->create(['name' => FiledFolder::ARCHIVE]), FiledFolder::query()->create(['name' => 'Other']), FiledFolder::query()->create()];

        foreach ([$archive, $other, $archive] as $home) {
            FiledDocument::query()->create(['folder_id' => $folder->id, 'home_folder_id' => $home->id]);
        }

        $positions = FiledDocument::query()->where('folder_id', $folder->id)->orderBy('id')->get()->map(fn (FiledDocument $document): null|int => $document->position)->all();

        $this->assertSame([1, null, 2], $positions);
    }

    #[Test]
    public function it_selects_the_position_under_the_alias_that_a_later_scope_gives_the_table(): void
    {
        [$first] = $this->playlist('audio', ['audio', 'audio']);

        $positions = Track::query()
            ->withGlobalScope('aliased', static function (Builder $query): void {
                $query->from('tracks as listed_tracks');
            })
            ->where('listed_tracks.playlist_id', $first->playlist_id)
            ->orderBy('listed_tracks.id')
            ->get()
            ->map(fn (Track $track): null|int => $track->position)
            ->all();

        $this->assertSame([1, 2], $positions);
    }

    #[Test]
    public function it_selects_the_pivot_position_beside_an_ordered_pivot_join_that_a_later_scope_adds(): void
    {
        $listing = Listing::create();
        $customers = array_map(fn (): Customer => Customer::query()->create(), range(1, 3));
        $listing->customers()->attach(array_map(fn (Customer $customer): int => $customer->id, $customers));

        $read = Customer::query()
            ->withGlobalScope('listed', static function (Builder $query) use ($listing): void {
                $query->join('listing_customer', 'listing_customer.customer_id', '=', 'customers.id')
                    ->where('listing_customer.listing_id', $listing->id)
                    ->select(['customers.*', 'listing_customer.id as pivot_id']);
            })
            ->orderBy('listing_customer.rank_key')
            ->get();

        $this->assertSame([1, 2, 3], $read->map(fn (Customer $customer): mixed => $customer->getAttribute(SelectsPosition::PIVOT_ATTRIBUTE))->all());
    }

    #[Test]
    public function it_keeps_the_declared_types_of_a_relation_read_and_its_position(): void
    {
        $section = Section::create();
        $this->asset($section);

        $assets = $section->assets()->get();
        $position = $assets->firstOrFail()->position;

        if (function_exists('PHPStan\Testing\assertType')) {
            assertType('Illuminate\Database\Eloquent\Collection<int, Tests\Fixtures\Support\Assets\Asset>', $assets);
            assertType('int|null', $position);
        }

        $this->assertSame(1, $position);
    }

    private function asset(Section $section, bool $visible = true, null|string $title = null): Asset
    {
        $asset = new Asset(['visible' => $visible, 'title' => $title]);
        $asset->section()->associate($section);
        $asset->save();

        return $asset;
    }

    private function root(): ComposedItem
    {
        $root = ComposedItem::withoutEvents(fn (): ComposedItem => ComposedItem::query()->create());

        return $root->fresh() ?? throw new LogicException('The root is gone.');
    }

    private function child(ComposedItem $parent, bool $hidden = false): ComposedItem
    {
        $child = new ComposedItem(['hidden' => $hidden]);
        $child->parent()->associate($parent);
        $child->save();

        return $child;
    }

    /**
     * @param  list<null|string>  $kinds
     * @return list<Track>
     */
    private function playlist(null|string $kind, array $kinds): array
    {
        $playlist = Playlist::create(['kind' => $kind]);

        return array_map(function (null|string $kind) use ($playlist): Track {
            $track = new Track(['kind' => $kind]);
            $track->playlist()->associate($playlist);
            $track->save();

            return $track;
        }, $kinds);
    }

    /**
     * @return list<Asset>
     */
    private static function restored(Asset $asset): array
    {
        $job = unserialize(serialize(new SendQueuedNotifications(new Collection([$asset]), new Notification)));

        assert($job instanceof SendQueuedNotifications);

        /** @var list<Asset> */
        return $job->notifiables->all();
    }

    /**
     * @return array{Section, list<Asset>}
     */
    private function racingSectionWith(int $count): array
    {
        $section = Section::on('racer')->create();
        $assets = [];

        for ($index = 0; $index < $count; $index++) {
            $asset = (new Asset)->setConnection('racer');
            $asset->section()->associate($section);
            $asset->save();

            $assets[] = $asset;
        }

        return [$section, $assets];
    }

    private function finishRace(Connection $racer, Section ...$sections): void
    {
        $ids = array_map(fn (Section $section): int => $section->id, $sections);

        $racer->transaction(function () use ($racer, $ids): void {
            $racer->table('assets')->whereIn('section_id', $ids)->delete();
            $racer->table('sections')->whereIn('id', $ids)->delete();
        });
    }
}
