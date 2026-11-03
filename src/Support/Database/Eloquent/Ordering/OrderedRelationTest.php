<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrderedThrough;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Events\Rebalanced;
use Support\Database\Eloquent\Ordering\Events\Reordered;
use Support\Database\Eloquent\Ordering\Exceptions\InvalidAnchor;
use Support\Database\Eloquent\Ordering\Exceptions\MembershipMismatch;
use Support\Database\Eloquent\Ordering\Exceptions\NotAMember;
use Support\Database\Eloquent\Ordering\Exceptions\ParentUnavailability;
use Support\Database\Eloquent\Ordering\Exceptions\UnavailableParent;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Agencies\Agency;
use Tests\Fixtures\Support\Agencies\AgencyCustomer;
use Tests\Fixtures\Support\Agencies\AgencyRevision;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Binders\Binder;
use Tests\Fixtures\Support\Composed\ComposedItem;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Documents\DatedDocument;
use Tests\Fixtures\Support\Documents\Document;
use Tests\Fixtures\Support\Documents\DocumentKind;
use Tests\Fixtures\Support\Documents\FiledDocument;
use Tests\Fixtures\Support\Documents\KindedDocument;
use Tests\Fixtures\Support\Documents\LocalizedDocument;
use Tests\Fixtures\Support\Drawers\Drawer;
use Tests\Fixtures\Support\Events\ModelEvents;
use Tests\Fixtures\Support\Folders\DatedFolder;
use Tests\Fixtures\Support\Folders\FiledFolder;
use Tests\Fixtures\Support\Folders\Folder;
use Tests\Fixtures\Support\Folders\FolderRevision;
use Tests\Fixtures\Support\Folders\KindedFolder;
use Tests\Fixtures\Support\Folders\LocalizedFolder;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingCustomer;
use Tests\Fixtures\Support\Listings\ListingRevision;
use Tests\Fixtures\Support\Listings\NamedListing;
use Tests\Fixtures\Support\Listings\PivotedListing;
use Tests\Fixtures\Support\Listings\TitledListing;
use Tests\Fixtures\Support\Listings\TwoPivotListing;
use Tests\Fixtures\Support\Notes\Note;
use Tests\Fixtures\Support\Pages\Page;
use Tests\Fixtures\Support\Playlists\Playlist;
use Tests\Fixtures\Support\Playlists\PlaylistRevision;
use Tests\Fixtures\Support\Scenes\Scene;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Shapes\BelongsToManyShape;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\Fixtures\Support\Shelves\Shelf;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Tracks\Track;
use Tests\Fixtures\Support\Videos\Video;
use Tests\TestCase;
use Throwable;

#[CoversClass(MembershipMismatch::class)]
#[CoversClass(OrderChange::class)]
#[CoversClass(OrderedRelation::class)]
#[CoversClass(OrderWrite::class)]
class OrderedRelationTest extends TestCase
{
    /** @var list<array{mixed, list<array{int|string, null|int, null|int}>}> */
    private array $reorders = [];

    protected function tearDown(): void
    {
        PivotedListing::$pivot = ListingCustomer::class;

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{Shape}>
     */
    public static function shapes(): iterable
    {
        return Shape::all();
    }

    /**
     * @return iterable<string, array{PivotShape}>
     */
    public static function pivots(): iterable
    {
        return PivotShape::pivots();
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function mismatches(): iterable
    {
        return Shape::crossed([
            'a member is missing' => [fn (array $ids, int|string $foreign): array => [$ids[2], $ids[1]]],
            'an id repeats' => [fn (array $ids, int|string $foreign): array => [$ids[2], $ids[1], $ids[0], $ids[0]]],
            'an id repeats as a string' => [fn (array $ids, int|string $foreign): array => [$ids[2], $ids[1], $ids[0], (string) $ids[0]]],
            'an id repeats in upper case' => [fn (array $ids, int|string $foreign): array => [$ids[2], $ids[1], $ids[0], strtoupper((string) $ids[0])]],
            'an id is foreign' => [fn (array $ids, int|string $foreign): array => [$ids[2], $ids[1], $ids[0], $foreign]],
            'a foreign id replaces a member' => [fn (array $ids, int|string $foreign): array => [$ids[2], $ids[1], $foreign]],
        ]);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function unchangedReorders(): iterable
    {
        return Shape::crossed([
            'the current order' => [3, fn (array $ids): array => $ids, true],
            'the current order without a comparison' => [3, fn (array $ids): array => $ids, false],
            'the only member' => [1, fn (array $ids): array => $ids, true],
            'an empty order' => [0, fn (array $ids): array => [], true],
        ]);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function positionedQueries(): iterable
    {
        return Shape::crossed([
            'a filter' => [fn (Relation $relation, string $column, array $ids): iterable => $relation->whereIn($column, $ids)->get()],
            'a filter and another order' => [fn (Relation $relation, string $column, array $ids): iterable => $relation->whereIn($column, $ids)->reorder($column, 'desc')->get()],
        ]);
    }

    /**
     * @return iterable<string, array{Closure(Agency, list<string>, Agency): mixed, list<int>, list<int>}>
     */
    public static function untimedPivotWrites(): iterable
    {
        yield 'a reorder' => [
            fn (Agency $agency, array $ids, Agency $other): OrderChange => $agency->customers()->arrange(array_reverse($ids), expected: null),
            [2, 1, 0],
            [],
        ];

        yield 'a rebalance' => [
            function (Agency $agency, array $ids, Agency $other): OrderChange {
                $prefix = 'a0'.str_repeat('V', OrderedRelation::MAX_KEY_LENGTH - 3);
                DB::table('agency_customer')->where('id', $ids[0])->update([OrderedRelation::RANK_KEY => $prefix.'V']);
                DB::table('agency_customer')->where('id', $ids[1])->update([OrderedRelation::RANK_KEY => $prefix.'W']);
                $pivot = Shape::inOrder($agency, AgencyRevision::CustomersOrder)->get()->all()[2]->getRelation('pivot');
                assert($pivot instanceof AgencyCustomer);

                return $pivot->move(Placement::after($ids[0]), expected: null);
            },
            [0, 2, 1],
            [],
        ];

        yield 'a move of a pivot that its query loaded' => [
            fn (Agency $agency, array $ids, Agency $other): OrderChange => AgencyCustomer::query()->findOrFail($ids[2])->move(Placement::first(), expected: null),
            [2, 0, 1],
            [],
        ];

        yield 'a move of a pivot that its query loaded to another parent' => [
            fn (Agency $agency, array $ids, Agency $other): OrderChange => AgencyCustomer::query()->findOrFail($ids[0])->move(Placement::last()->in($other), expected: null),
            [1, 2],
            [0],
        ];

        yield 'a detach' => [
            fn (Agency $agency, array $ids, Agency $other): int => $agency->customers()->detach(AgencyCustomer::query()->findOrFail($ids[1])->customer_id),
            [0, 2],
            [],
        ];
    }

    /**
     * @return iterable<string, array{Closure(): array{Model&OrdersRelations, string, list<Model&Ordered>}}>
     */
    public static function declaredFilters(): iterable
    {
        yield 'a parent column' => [static fn (): array => self::filtered(Playlist::create(['kind' => 'audio']), 'tracks', Track::class, 'playlist_id', 'kind', ['audio', 'video', 'audio', 'audio'])];
        yield 'a parent column of an ordered parent' => [static fn (): array => self::filtered(Binder::query()->create(['shelf_id' => Shelf::create()->id, 'kind' => 'notes']), 'pages', Page::class, 'binder_id', 'kind', ['notes', 'drafts', 'notes', 'notes'])];
        yield 'a runtime value' => [static fn (): array => self::filtered(LocalizedFolder::query()->create(), 'documents', LocalizedDocument::class, 'folder_id', 'locale', ['en', 'fr', 'en', 'en'])];
        yield 'a parent key' => [static function (): array {
            [$folder, $other] = [Folder::query()->create(), Folder::query()->create()];

            return self::filtered($folder, 'documents', Document::class, 'folder_id', 'home_folder_id', [$folder->id, $other->id, $folder->id, $folder->id]);
        }];
        yield 'a date constant' => [static fn (): array => self::filtered(DatedFolder::query()->create(), 'documents', DatedDocument::class, 'folder_id', 'published_at', ['2025-06-01 00:00:00', '2026-06-01 00:00:00', '2025-12-01 00:00:00', DatedFolder::PUBLISHED_BY])];
        yield 'a bound subquery' => [static function (): array {
            [$archive, $other] = [FiledFolder::query()->create(['name' => FiledFolder::ARCHIVE]), FiledFolder::query()->create(['name' => 'Other'])];

            return self::filtered(FiledFolder::query()->create(), 'documents', FiledDocument::class, 'folder_id', 'home_folder_id', [$archive->id, $other->id, $archive->id, $archive->id]);
        }];
        yield 'an enum cast' => [static fn (): array => self::filtered(KindedFolder::query()->create(['kind' => DocumentKind::Notes]), 'documents', KindedDocument::class, 'folder_id', 'kind', ['notes', 'drafts', 'notes', 'notes'])];
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_selects_the_position_of_every_member_in_the_query_that_reads_it_as_the_order_change_numbers_it(Shape $shape): void
    {
        [$parent, $members] = $shape->with(4);
        $shape->add($parent, hidden: true);
        $change = $members[2]->move(Placement::first(), expected: null);
        $shape->ordered($parent)->get();
        DB::enableQueryLog();

        $positions = $shape->selectedPositions($shape->ordered($parent)->get());

        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame(array_combine($change->order, range(1, count($change->order))), $positions);
        $this->assertSame($positions, array_map(fn (int|string $id): mixed => $shape->find($id)->getAttribute('position'), array_combine(array_keys($positions), array_keys($positions))));
    }

    /**
     * @param  Closure(): array{Model&OrdersRelations, string, list<Model&Ordered>}  $create
     */
    #[DataProvider('declaredFilters')]
    #[Test]
    public function it_selects_the_position_of_every_member_that_a_declared_filter_keeps_as_the_order_change_numbers_it(Closure $create): void
    {
        [$parent, $relation, $created] = $create();
        $change = $created[3]->move(Placement::first(), expected: null);
        $expected = array_combine($change->order, range(1, count($change->order)));
        $position = fn (Model $member): array => [$member->getKey() => $member->getAttribute('position')];

        $related = $parent->{$relation}()->reorder($created[0]->qualifyColumn('rank_key'))->orderBy($created[0]->getQualifiedKeyName())->get()->mapWithKeys($position)->all();
        $read = $created[0]->newQuery()->whereKey(array_map(fn (Model $member): mixed => $member->getKey(), $created))->get()->mapWithKeys($position)->sortKeys()->all();
        $unfiltered = $expected + [$created[1]->getKey() => null];
        ksort($unfiltered);

        $this->assertCount(3, $change->order);
        $this->assertSame($expected, $related);
        $this->assertSame($unfiltered, $read);
    }

    #[DataProvider('positionedQueries')]
    #[Test]
    public function it_selects_for_the_members_a_filter_keeps_their_place_among_every_member(Shape $shape, Closure $query): void
    {
        [$parent, [, $second, , $fourth]] = $shape->with(4);

        $positions = $shape->selectedPositions($query($shape->ordered($parent), $shape->memberTable().'.id', [$second->getKey(), $fourth->getKey()]));

        $this->assertEquals([$second->getKey() => 2, $fourth->getKey() => 4], $positions);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_keeps_the_position_of_each_member_on_a_later_page(Shape $shape): void
    {
        [$parent, $members] = $shape->with(5);

        $page = $shape->ordered($parent)->paginate(2, page: 2);

        $this->assertSame(5, $page->total());
        $this->assertSame([$members[2]->getKey() => 3, $members[3]->getKey() => 4], $shape->selectedPositions($page->items()));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_numbers_no_trashed_member(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $second->delete();

        $positions = $shape->selectedPositions($shape->ordered($parent)->get());

        $this->assertSame([$first->getKey() => 1, $third->getKey() => 2], $positions);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_numbers_no_pivot_whose_customer_is_trashed(PivotShape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        Customer::query()->findOrFail($second->getAttribute('customer_id'))->delete();

        $positions = $shape->selectedPositions($shape->ordered($parent)->get());

        $this->assertSame([$first->getKey() => 1, $third->getKey() => 2], $positions);
        $this->assertNull($shape->find($this->key($second))->getAttribute('position'));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_gives_no_position_to_a_member_without_a_rank_key(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        DB::table($shape->memberTable())->where('id', $first->getKey())->update([OrderedRelation::RANK_KEY => null]);

        $positions = $shape->selectedPositions($shape->ordered($parent)->get());

        $this->assertSame([$second->getKey() => 1, $third->getKey() => 2, $first->getKey() => null], $positions);
        $this->assertNull($shape->find($this->key($first))->getAttribute('position'));
    }

    #[Test]
    public function it_selects_the_pivot_position_beside_a_join_that_binds_its_own_value(): void
    {
        $shape = new BelongsToManyShape;
        [$listing, [$first, $second, $third]] = $shape->with(3);
        Customer::query()->whereKey([$first->getAttribute('customer_id'), $third->getAttribute('customer_id')])->update(['name' => 'Kept']);

        $results = $shape->ordered($listing)
            ->leftJoin('customers as kept', fn (JoinClause $join): JoinClause => $join->on('kept.id', '=', 'customers.id')->where('kept.name', '=', 'Kept'))
            ->addSelect('kept.name as kept_name')
            ->get();

        $this->assertSame([$first->getKey() => 1, $second->getKey() => 2, $third->getKey() => 3], $shape->selectedPositions($results));
        $this->assertSame(['Kept', null, 'Kept'], $results->pluck('kept_name')->all());
    }

    /**
     * @param  Closure(Agency, list<string>, Agency): mixed  $write
     * @param  list<int>  $order
     * @param  list<int>  $moved
     */
    #[DataProvider('untimedPivotWrites')]
    #[Test]
    public function it_writes_a_pivot_whose_relation_and_table_have_no_timestamps(Closure $write, array $order, array $moved): void
    {
        [$agency, $other] = [Agency::create(), Agency::create()];
        $agency->customers()->attach([Customer::create()->id, Customer::create()->id, Customer::create()->id]);
        $ids = $this->agencyMembers($agency);

        $write($agency, $ids, $other);

        $this->assertTrue((new AgencyCustomer)->usesTimestamps());
        $this->assertSame(array_map(fn (int $index): string => $ids[$index], $order), $this->agencyMembers($agency));
        $this->assertSame(array_map(fn (int $index): string => $ids[$index], $moved), $this->agencyMembers($other));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_queries_the_members_in_order(Shape $shape): void
    {
        $parent = $shape->createParent();
        $third = $shape->add($parent);
        $first = $shape->add($parent, Placement::first());
        $second = $shape->add($parent, Placement::after($this->key($first)));
        $shape->add($shape->createParent());

        $this->assertSame([$first->getKey(), $second->getKey(), $third->getKey()], $shape->members($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_arranges_through_the_parents_relation_beside_the_reorder_of_its_query(Shape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);
        $relation = $shape->relation($parent);

        $cleared = $relation->orderByDesc('id')->reorder();
        $change = $shape->relation($parent)->arrange([$this->key($second), $this->key($first)], expected: null);

        $this->assertSame($shape->relationClass(), $relation::class);
        $this->assertSame($relation, $cleared);
        $this->assertSame([], $cleared->getBaseQuery()->orders ?? []);
        $this->assertSame($parent, $change->parent);
        $this->assertSame($shape->revision(), $change->revision);
        $this->assertSame([$second->getKey(), $first->getKey()], $change->order);
        $this->assertSame([$second->getKey(), $first->getKey()], $shape->members($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_arranges_every_member_a_global_scope_hides_but_no_trashed_member(Shape $shape): void
    {
        $parent = $shape->createParent();
        $hidden = $shape->add($parent, hidden: true);
        $trashed = $shape->add($parent);
        $visible = $shape->add($parent);

        $trashed->delete();
        $change = $shape->arrange($parent, [$this->key($visible), $this->key($hidden)], expected: null);

        $this->assertSame([$visible->getKey(), $hidden->getKey()], $change->order);
        $this->assertSame([$visible->getKey(), $hidden->getKey()], $shape->members($parent));
        $this->assertRejected($shape, MembershipMismatch::class, $parent, fn (): OrderChange => $shape->arrange($parent, [$this->key($visible)], expected: null));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_returns_the_locked_members_in_order_with_their_positions_including_one_a_global_scope_hides_but_no_trashed_member(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $hidden = $shape->add($parent, Placement::first(), hidden: true);
        $shape->add($parent)->delete();
        $shape->arrange($parent, [$this->key($third), $this->key($hidden), $this->key($first), $this->key($second)], expected: null);

        $members = DB::transaction(fn (): mixed => $shape->relation($parent)->lockedMembers());

        $this->assertSame(
            [$third->getKey() => 1, $hidden->getKey() => 2, $first->getKey() => 3, $second->getKey() => 4],
            $shape->selectedPositions($members),
        );
    }

    #[Test]
    public function it_locks_the_parent_and_each_model_to_also_lock_in_lock_order_before_it_reads_the_members(): void
    {
        [$lower, $higher] = [Section::create(), Section::create()];
        $asset = Asset::create(['section_id' => $higher->id]);
        DB::enableQueryLog();

        $members = DB::transaction(fn (): mixed => $higher->assets()->lockedMembers($lower));

        $statements = DB::getQueryLog();
        $read = array_find_key($statements, fn (array $statement): bool => str_starts_with($statement['query'], 'select "assets".*'));
        $locks = array_values(array_filter($statements, fn (array $statement, int $index): bool => $index < $read && str_contains($statement['query'], 'from "sections"') && str_ends_with($statement['query'], 'for no key update'), ARRAY_FILTER_USE_BOTH));

        $this->assertSame([$asset->id], $members->modelKeys());
        $this->assertSame([[$lower->id], [$higher->id]], array_slice(array_column($locks, 'bindings'), 0, 2));
    }

    #[Test]
    public function it_locks_the_ordering_parents_of_a_parent_that_is_also_a_member_and_reads_its_membership_from_the_locked_row(): void
    {
        $shelf = Shelf::create();
        $binder = (new Binder(['kind' => 'audio']))->shelf()->associate($shelf);
        $binder->save();
        $audio = Page::create(['binder_id' => $binder->id, 'kind' => 'audio']);
        $video = Page::create(['binder_id' => $binder->id, 'kind' => 'video']);
        DB::table('binders')->where('id', $binder->id)->update(['kind' => 'video']);
        DB::enableQueryLog();

        $members = DB::transaction(fn (): mixed => $binder->pages()->lockedMembers());

        $statements = DB::getQueryLog();
        $read = array_find_key($statements, fn (array $statement): bool => str_starts_with($statement['query'], 'select "pages".*'));
        $locked = array_filter($statements, fn (array $statement, int $index): bool => $index < $read && str_ends_with($statement['query'], 'for no key update'), ARRAY_FILTER_USE_BOTH);

        $this->assertSame([$video->id], $members->modelKeys());
        $this->assertSame('video', $binder->kind);
        $this->assertNotContains($audio->id, $members->modelKeys());
        $this->assertSame(
            [['shelves', $shelf->id], ['binders', $binder->id]],
            array_slice(array_map(fn (array $statement): array => [str_contains($statement['query'], 'from "binders"') ? 'binders' : 'shelves', $statement['bindings'][0]], array_values($locked)), 0, 2),
        );
    }

    #[Test]
    public function it_locks_the_stored_ordering_parent_of_a_parent_that_moved_after_it_was_read_before_the_parent(): void
    {
        [$read, $stored] = [Shelf::create(), Shelf::create()];
        $binder = Binder::query()->findOrFail(Binder::create(['shelf_id' => $read->id, 'kind' => 'audio'])->id);
        DB::table('binders')->where('id', $binder->id)->update(['shelf_id' => $stored->id]);
        DB::enableQueryLog();

        DB::transaction(fn (): mixed => $binder->pages()->lockedMembers());

        $this->assertSame([['shelves', $read->id], ['shelves', $stored->id], ['binders', $binder->id], ['binders', $binder->id]], $this->parentLocks(DB::getQueryLog()));
    }

    #[Test]
    public function it_locks_the_members_again_when_the_parent_moves_between_the_read_of_its_ordering_parent_and_the_lock(): void
    {
        $racer = $this->racer();
        config()->set('database.connections.mover', config('database.connections.pgsql'));
        [$first, $second] = [Shelf::on('racer')->create(), Shelf::on('racer')->create()];
        $binder = Binder::on('racer')->create(['shelf_id' => $first->id, 'kind' => 'audio']);
        $page = Page::on('racer')->create(['binder_id' => $binder->id, 'kind' => 'audio']);
        $binder = Binder::on('racer')->findOrFail($binder->id);
        $moved = false;

        try {
            DB::listen(function (QueryExecuted $query) use (&$moved, $binder, $second): void {
                if ($moved || $query->connectionName !== 'racer' || ! str_contains($query->sql, 'from "shelves"') || ! str_ends_with($query->sql, 'for no key update')) {
                    return;
                }

                $moved = true;
                DB::connection('mover')->statement("set lock_timeout = '2s'");
                DB::connection('mover')->table('binders')->where('id', $binder->id)->update(['shelf_id' => $second->id]);
            });
            $racer->enableQueryLog();

            $members = $racer->transaction(fn (): mixed => $binder->pages()->lockedMembers());

            $locks = $this->parentLocks($racer->getQueryLog());

            $this->assertTrue($moved, 'The parent never moved while the members were locked.');
            $this->assertSame([$page->id], $members->modelKeys());
            $this->assertSame([
                ['shelves', $first->id], ['binders', $binder->id], ['binders', $binder->id],
                ['shelves', $first->id], ['shelves', $second->id], ['binders', $binder->id], ['binders', $binder->id],
            ], $locks);
        } finally {
            $racer->table('pages')->where('binder_id', $binder->id)->delete();
            $racer->table('binders')->where('id', $binder->id)->delete();
            $racer->table('shelves')->whereIn('id', [$first->id, $second->id])->delete();
        }
    }

    #[Test]
    public function it_refuses_to_lock_an_unsaved_model_or_one_on_another_connection_with_the_members_before_it_locks_anything(): void
    {
        $this->racer();
        $section = Section::create();
        $elsewhere = Section::create()->setConnection('racer');
        DB::enableQueryLog();

        DB::transaction(function () use ($section, $elsewhere): void {
            foreach ([
                sprintf('[%s] must be saved before it is locked with the members of [%s:%s].', Section::class, $section->getMorphClass(), $section->id) => new Section,
                sprintf('[%s:%s] on the [racer] connection cannot be locked with the members of [%s:%s] on the [pgsql] connection.', Section::class, $elsewhere->id, $section->getMorphClass(), $section->id) => $elsewhere,
            ] as $message => $model) {
                try {
                    $section->assets()->lockedMembers($model);
                    $this->fail('The members were locked with a model that the transaction cannot lock.');
                } catch (LogicException $exception) {
                    $this->assertSame($message, $exception->getMessage());
                }
            }
        });

        $this->assertSame([], array_filter(DB::getQueryLog(), fn (array $statement): bool => str_ends_with($statement['query'], 'for no key update')));
    }

    #[Test]
    public function it_refuses_to_lock_an_unsaved_model_that_shares_the_key_of_the_parent_with_the_members(): void
    {
        $section = Section::create();
        $twin = (new Section)->setConnection($section->getConnectionName())->forceFill(['id' => $section->id]);
        DB::enableQueryLog();

        DB::transaction(function () use ($section, $twin): void {
            try {
                $section->assets()->lockedMembers($twin);
                $this->fail('The members were locked with an unsaved model.');
            } catch (LogicException $exception) {
                $this->assertSame(sprintf('[%s] must be saved before it is locked with the members of [%s:%s].', Section::class, $section->getMorphClass(), $section->id), $exception->getMessage());
            }
        });

        $this->assertSame([], array_filter(DB::getQueryLog(), fn (array $statement): bool => str_ends_with($statement['query'], 'for no key update')));
    }

    #[Test]
    public function it_finishes_locking_the_members_of_a_parent_beside_a_move_of_that_parent_in_its_own_order_without_a_deadlock(): void
    {
        $racer = $this->racer();
        $shelf = Shelf::on('racer')->create();
        $staying = Binder::on('racer')->create(['shelf_id' => $shelf->id, 'kind' => 'audio']);
        $moving = Binder::on('racer')->create(['shelf_id' => $shelf->id, 'kind' => 'audio']);
        $page = Page::on('racer')->create(['binder_id' => $moving->id, 'kind' => 'audio']);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('shelves')->where('id', $shelf->id)->lockForUpdate()->first();

            $rivals[] = $this->startRival('move-binder.php', (string) $moving->id);
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startRival('locked-pages.php', (string) $moving->id);
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $outputs = array_map(fn (InvokedProcess $rival): mixed => json_decode($rival->wait()->output(), true), $rivals);

            $this->assertSame([['moved'], ['locked', [$page->id]]], $outputs, implode(PHP_EOL, array_map(fn (InvokedProcess $rival): string => $rival->errorOutput(), $rivals)));
            $this->assertSame([$moving->id, $staying->id], $racer->table('binders')->where('shelf_id', $shelf->id)->orderBy('rank_key')->pluck('id')->all());
        } finally {
            if ($racer->transactionLevel() > 0) {
                $racer->rollBack();
            }

            foreach ($rivals as $rival) {
                $rival->wait();
            }

            $racer->table('pages')->where('binder_id', $moving->id)->delete();
            $racer->table('binders')->where('shelf_id', $shelf->id)->delete();
            $racer->table('shelves')->where('id', $shelf->id)->delete();
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mixedDepthLocks(): array
    {
        return [
            'the pages of the binder of the page' => ['binder'],
            'the binders of the shelf of the page' => ['shelf'],
        ];
    }

    #[DataProvider('mixedDepthLocks')]
    #[Test]
    public function it_finishes_locking_members_with_a_model_of_another_depth_beside_a_move_of_a_binder_in_its_shelf_without_a_deadlock(string $parent): void
    {
        $racer = $this->racer();
        $shelf = Shelf::on('racer')->create();
        $staying = Binder::on('racer')->create(['shelf_id' => $shelf->id, 'kind' => 'audio']);
        $moving = Binder::on('racer')->create(['shelf_id' => $shelf->id, 'kind' => 'audio']);
        $page = Page::on('racer')->create(['binder_id' => $moving->id, 'kind' => 'audio']);

        try {
            [$outputs, $errors] = $this->raceBehindLock($racer, 'shelves', $shelf->id, [
                ['move-binder.php', (string) $moving->id],
                ['locked-with-page.php', $parent, (string) ($parent === 'shelf' ? $shelf->id : $moving->id), (string) $page->id],
            ]);

            $this->assertSame([['moved'], ['locked', $parent === 'shelf' ? [$moving->id, $staying->id] : [$page->id]]], $outputs, $errors);
            $this->assertSame([$moving->id, $staying->id], $racer->table('binders')->where('shelf_id', $shelf->id)->orderBy('rank_key')->pluck('id')->all());
        } finally {
            $racer->table('pages')->where('binder_id', $moving->id)->delete();
            $racer->table('binders')->where('shelf_id', $shelf->id)->delete();
            $racer->table('shelves')->where('id', $shelf->id)->delete();
        }
    }

    #[Test]
    public function it_finishes_locking_members_with_a_binder_that_changed_shelf_since_it_was_read_beside_a_move_of_that_binder_without_a_deadlock(): void
    {
        $racer = $this->racer();
        [$read, $stored] = [Shelf::on('racer')->create(), Shelf::on('racer')->create()];
        $staying = Binder::on('racer')->create(['shelf_id' => $stored->id, 'kind' => 'audio']);
        $binder = Binder::on('racer')->create(['shelf_id' => $stored->id, 'kind' => 'audio']);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('shelves')->where('id', $stored->id)->lockForUpdate()->first();

            $rivals[] = $this->startRival('move-binder.php', (string) $binder->id);
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startRival('locked-stale-binder.php', (string) $read->id, (string) $binder->id);
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $outputs = array_map(fn (InvokedProcess $rival): mixed => json_decode($rival->wait()->output(), true), $rivals);

            $this->assertSame([['moved'], ['moved']], $outputs, implode(PHP_EOL, array_map(fn (InvokedProcess $rival): string => $rival->errorOutput(), $rivals)));
            $this->assertSame([$binder->id, $staying->id], $racer->table('binders')->where('shelf_id', $stored->id)->orderBy('rank_key')->pluck('id')->all());
        } finally {
            if ($racer->transactionLevel() > 0) {
                $racer->rollBack();
            }

            foreach ($rivals as $rival) {
                $rival->wait();
            }

            $racer->table('binders')->where('shelf_id', $stored->id)->delete();
            $racer->table('shelves')->whereIn('id', [$read->id, $stored->id])->delete();
        }
    }

    #[Test]
    public function it_locks_the_members_again_when_a_model_to_also_lock_moves_between_the_read_of_its_ordering_parent_and_the_lock(): void
    {
        $racer = $this->racer();
        config()->set('database.connections.mover', config('database.connections.pgsql'));
        [$first, $second] = [Shelf::on('racer')->create(), Shelf::on('racer')->create()];
        $section = Section::on('racer')->create();
        $binder = Binder::on('racer')->findOrFail(Binder::on('racer')->create(['shelf_id' => $first->id, 'kind' => 'audio'])->id);
        $moved = false;

        try {
            DB::listen(function (QueryExecuted $query) use (&$moved, $binder, $second): void {
                if ($moved || $query->connectionName !== 'racer' || ! str_contains($query->sql, 'from "shelves"') || ! str_ends_with($query->sql, 'for no key update')) {
                    return;
                }

                $moved = true;
                DB::connection('mover')->statement("set lock_timeout = '2s'");
                DB::connection('mover')->table('binders')->where('id', $binder->id)->update(['shelf_id' => $second->id]);
            });
            $racer->enableQueryLog();

            $racer->transaction(fn (): mixed => $section->assets()->lockedMembers($binder));

            $this->assertTrue($moved, 'The model to also lock never moved while the members were locked.');
            $this->assertSame([
                ['shelves', $first->id], ['binders', $binder->id],
                ['shelves', $first->id], ['shelves', $second->id], ['binders', $binder->id],
            ], $this->parentLocks($racer->getQueryLog()));
        } finally {
            $racer->table('binders')->where('id', $binder->id)->delete();
            $racer->table('shelves')->whereIn('id', [$first->id, $second->id])->delete();
            $racer->table('sections')->where('id', $section->id)->delete();
        }
    }

    #[Test]
    public function it_locks_the_members_again_when_a_model_to_also_lock_reaches_another_parent_through_a_pivot_between_the_read_of_its_parents_and_the_lock(): void
    {
        $racer = $this->racer();
        config()->set('database.connections.mover', config('database.connections.pgsql'));
        [$first, $second] = [Listing::on('racer')->create(), Listing::on('racer')->create()];
        $customer = Customer::on('racer')->create();
        $first->customers()->attach($customer->id);
        $attached = false;

        try {
            DB::listen(function (QueryExecuted $query) use (&$attached, $customer, $second): void {
                if ($attached || $query->connectionName !== 'racer' || ! str_contains($query->sql, 'from "listings"') || ! str_ends_with($query->sql, 'for no key update')) {
                    return;
                }

                $attached = true;
                DB::connection('mover')->statement("set lock_timeout = '2s'");
                DB::connection('mover')->table('listing_customer')->insert(['id' => (string) Str::uuid(), 'listing_id' => $second->id, 'customer_id' => $customer->id, 'rank_key' => 'a0']);
            });
            $racer->enableQueryLog();

            $members = $racer->transaction(fn (): mixed => $first->customers()->lockedMembers($customer));

            $this->assertTrue($attached, 'The model to also lock never reached another parent while the members were locked.');
            $this->assertSame([$customer->id], $members->modelKeys());
            $this->assertSame([
                ['listings', $first->id], ['customers', $customer->id], ['listings', $first->id],
                ['listings', $first->id], ['listings', $second->id], ['customers', $customer->id], ['listings', $first->id],
            ], $this->parentLocks($racer->getQueryLog(), 'listings|customers'));
        } finally {
            $racer->table('listing_customer')->where('customer_id', $customer->id)->delete();
            $racer->table('customers')->where('id', $customer->id)->delete();
            $racer->table('listings')->whereIn('id', [$first->id, $second->id])->delete();
        }
    }

    #[Test]
    public function it_finishes_locking_the_children_of_a_composed_item_with_a_child_beside_a_move_of_a_grandchild_into_it_without_a_deadlock(): void
    {
        $racer = $this->racer();
        [$lower, $higher] = self::ascendingUuids();
        $root = ComposedItem::withoutEvents(fn (): ComposedItem => tap((new ComposedItem)->setConnection('racer')->forceFill(['id' => $higher]))->save());
        $child = self::racerComposedItemIn($root, $lower);
        $grandchild = self::racerComposedItemIn($child);

        try {
            [$outputs, $errors] = $this->raceBehindLock($racer, 'composed_items', $child->id, [
                ['composed.php', 'move', $grandchild->id, $root->id],
                ['composed.php', 'locked', $root->id, $child->id],
            ]);

            $this->assertSame([['moved'], ['locked', [$child->id, $grandchild->id]]], $outputs, $errors);
        } finally {
            $racer->table('composed_items')->whereIn('id', [$grandchild->id, $child->id, $root->id])->delete();
        }
    }

    #[Test]
    public function it_moves_a_member_into_a_parent_held_with_a_stale_parent_beside_an_opposite_move_without_a_deadlock(): void
    {
        $racer = $this->racer();
        $root = ComposedItem::withoutEvents(fn (): ComposedItem => tap((new ComposedItem)->setConnection('racer'))->save());
        [$first, $second] = array_map(static fn (string $id): ComposedItem => self::racerComposedItemIn($root, $id), self::ascendingUuids());
        [$inFirst, $inSecond] = array_map(static fn (ComposedItem $parent): ComposedItem => self::racerComposedItemIn($parent), [$first, $second]);

        try {
            [$outputs, $errors] = $this->raceBehindLock($racer, 'composed_items', $first->id, [
                ['composed.php', 'move', $inFirst->id, $second->id],
                ['composed.php', 'stale', $inSecond->id, $first->id, $second->id],
            ]);

            $this->assertSame([['moved'], ['moved']], $outputs, $errors);
            $this->assertSame([$second->id, $first->id], [
                $racer->table('composed_items')->where('id', $inFirst->id)->value('parent_id'),
                $racer->table('composed_items')->where('id', $inSecond->id)->value('parent_id'),
            ]);
        } finally {
            $racer->table('composed_items')->whereIn('id', [$inFirst->id, $inSecond->id])->delete();
            $racer->table('composed_items')->whereIn('id', [$first->id, $second->id])->delete();
            $racer->table('composed_items')->where('id', $root->id)->delete();
        }
    }

    #[Test]
    public function it_returns_no_members_of_a_held_parent_whose_row_is_gone(): void
    {
        $tour = Tour::create();
        $tour->scenes()->create();
        $tour->scenes()->create();
        DB::table('tours')->where('id', $tour->id)->delete();

        $members = DB::transaction(fn (): mixed => $tour->scenes()->lockedMembers());

        $this->assertSame([], $members->all());
    }

    #[Test]
    public function it_refuses_to_lock_the_members_outside_a_transaction(): void
    {
        $this->racer();
        $section = (new Section)->setConnection('racer')->forceFill(['id' => 1]);

        try {
            $section->assets()->lockedMembers();
            $this->fail('The members were locked outside a transaction.');
        } catch (LogicException $exception) {
            $this->assertSame(sprintf('Lock the members of [%s:1] inside a transaction, because its row locks end with the statement otherwise.', $section->getMorphClass()), $exception->getMessage());
        }
    }

    #[Test]
    public function it_finishes_a_locked_move_out_of_a_parent_and_its_delete_beside_an_opposite_locked_move_without_a_deadlock(): void
    {
        $racer = $this->racer();
        [$emptied, $other] = [Drawer::on('racer')->create(), Drawer::on('racer')->create()];
        $leaving = [$this->addNote($emptied, 'racer'), $this->addNote($emptied, 'racer')];
        $staying = [$this->addNote($other, 'racer')];
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('drawers')->where('id', $emptied->id)->lockForUpdate()->first();

            $rivals[] = $this->startRival('locked-members.php', (string) $emptied->id, (string) $other->id, 'delete');
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startRival('locked-members.php', (string) $other->id, (string) $emptied->id);
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $outputs = array_map(fn (InvokedProcess $rival): mixed => json_decode($rival->wait()->output(), true), $rivals);
            $ids = fn (array $notes): array => array_map(fn (Note $note): int => $note->id, $notes);

            $this->assertSame([['moved', $ids($leaving)], ['unavailable', 'trashed']], $outputs, implode(PHP_EOL, array_map(fn (InvokedProcess $rival): string => $rival->errorOutput(), $rivals)));
            $this->assertNotNull($racer->table('drawers')->where('id', $emptied->id)->value('deleted_at'));
            $this->assertSame([...$ids($staying), ...$ids($leaving)], $racer->table('notes')->where('drawer_id', $other->id)->orderBy('rank_key')->pluck('id')->all());
        } finally {
            if ($racer->transactionLevel() > 0) {
                $racer->rollBack();
            }

            foreach ($rivals as $rival) {
                $rival->wait();
            }

            $racer->table('notes')->whereIn('drawer_id', [$emptied->id, $other->id])->delete();
            $racer->table('drawers')->whereIn('id', [$emptied->id, $other->id])->delete();
        }
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_moves_members_of_several_parents_into_one_placement_in_the_order_given(Shape $shape): void
    {
        [$first, [$a0, $a1, $a2]] = $shape->with(3);
        [$second, [$b0, $b1]] = $shape->with(2);
        [$destination, [$d0, $d1]] = $shape->with(2);

        $change = $shape->relation($destination)->moveIn([$a2, $b1, $a0], expected: null, placement: Placement::after($this->key($d0)));

        $order = [$d0->getKey(), $a2->getKey(), $b1->getKey(), $a0->getKey(), $d1->getKey()];
        $this->assertSame($destination, $change->parent);
        $this->assertSame($shape->revision(), $change->revision);
        $this->assertSame($order, $change->order);
        $this->assertSame(
            [['id' => $a2->getKey(), 'position' => 2, 'previousPosition' => null], ['id' => $b1->getKey(), 'position' => 3, 'previousPosition' => null], ['id' => $a0->getKey(), 'position' => 4, 'previousPosition' => null]],
            array_map(get_object_vars(...), $change->changed),
        );
        $this->assertNull($change->source);
        $this->assertSame($shape->digest($destination), $change->digest);
        $this->assertSame($order, $shape->members($destination));
        $this->assertSame([$a1->getKey()], $shape->members($first));
        $this->assertSame([$b0->getKey()], $shape->members($second));
        $this->assertSame([2, 3, 4], [$a2->getAttribute('position'), $b1->getAttribute('position'), $a0->getAttribute('position')]);
        $this->assertEquals([$destination->getKey(), $destination->getKey()], [$a2->getAttribute($shape->foreignKey()), $b1->getAttribute($shape->foreignKey())]);
        $this->assertFalse($a2->isDirty());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_moves_members_already_in_the_destination_beside_one_that_enters(Shape $shape): void
    {
        [$source, [$entering]] = $shape->with(1);
        [$destination, [$d0, $d1, $d2]] = $shape->with(3);

        $change = $shape->relation($destination)->moveIn([$d2, $entering, $d0], expected: null, placement: Placement::first());

        $this->assertSame([$d2->getKey(), $entering->getKey(), $d0->getKey(), $d1->getKey()], $change->order);
        $this->assertSame($change->order, $shape->members($destination));
        $this->assertSame(
            [['id' => $d2->getKey(), 'position' => 1, 'previousPosition' => 3], ['id' => $entering->getKey(), 'position' => 2, 'previousPosition' => null], ['id' => $d0->getKey(), 'position' => 3, 'previousPosition' => 1]],
            array_map(get_object_vars(...), $change->changed),
        );
        $this->assertNotNull($change->source);
        $this->assertSame($source, $change->source->parent);
        $this->assertSame([['id' => $entering->getKey(), 'position' => null, 'previousPosition' => 1]], array_map(get_object_vars(...), $change->source->changed));
        $this->assertSame([], $shape->members($source));
    }

    #[Test]
    public function it_moves_a_member_into_the_destination_and_follows_its_loaded_parent(): void
    {
        [$source, $destination] = [Section::create(), Section::create()];
        $asset = Asset::create(['section_id' => $source->id]);
        $asset->setRelation('section', $source);

        $destination->assets()->moveIn([$asset], expected: $destination->concurrencyRevision(SectionRevision::AssetsOrder));

        $this->assertSame($destination, $asset->getRelation('section'));
        $this->assertSame($destination->id, $asset->section_id);
        $this->assertSame(1, $asset->position);
    }

    #[Test]
    public function it_refuses_a_move_in_that_names_members_its_order_no_longer_holds_and_changes_nothing(): void
    {
        $destination = Tour::create();
        $destination->scenes()->create();
        $gone = Tour::create();
        $orphan = $gone->scenes()->create();
        $source = Tour::create();
        [$live, $trashed] = [$source->scenes()->create(), $source->scenes()->create()];
        Scene::query()->findOrFail($trashed->id)->delete();
        DB::table('tours')->where('id', $gone->id)->delete();
        $before = DB::table('scenes')->orderBy('id')->get()->all();

        try {
            $destination->scenes()->moveIn([$orphan, $live, $trashed], expected: null);
            $this->fail('A move in of members that their order no longer holds succeeded.');
        } catch (NotAMember $exception) {
            $this->assertSame(Scene::class, $exception->class);
            $this->assertSame([$orphan->id, $trashed->id], $exception->ids);
        }

        $this->assertEquals($before, DB::table('scenes')->orderBy('id')->get()->all());
    }

    #[Test]
    public function it_refuses_a_move_in_to_a_trashed_or_missing_parent_but_moves_members_inside_a_trashed_one(): void
    {
        [$trashed, $other, $missing] = [Drawer::create(), Drawer::create(), Drawer::create()];
        [$first, $second] = [$this->addNote($trashed, 'pgsql'), $this->addNote($trashed, 'pgsql')];
        $outside = $this->addNote($other, 'pgsql');
        $trashed->delete();
        DB::table('drawers')->where('id', $missing->id)->delete();
        $before = DB::table('notes')->orderBy('id')->get()->all();

        foreach ([[ParentUnavailability::Trashed, $trashed], [ParentUnavailability::Missing, $missing]] as [$reason, $parent]) {
            try {
                $parent->notes()->moveIn([$outside], expected: null);
                $this->fail('A move in placed a member in a parent that takes none.');
            } catch (UnavailableParent $exception) {
                $this->assertSame($parent, $exception->parent);
                $this->assertSame($reason, $exception->reason);
            }
        }

        $this->assertEquals($before, DB::table('notes')->orderBy('id')->get()->all());
        $this->assertSame([$second->id, $first->id], $trashed->notes()->moveIn([$second], expected: null, placement: Placement::first())->order);
    }

    #[Test]
    public function it_leaves_no_position_on_members_moved_in_inside_a_trashed_parent(): void
    {
        $drawer = Drawer::create();
        [$first, $second, $third] = [$this->addNote($drawer, 'pgsql'), $this->addNote($drawer, 'pgsql'), $this->addNote($drawer, 'pgsql')];
        $drawer->delete();

        $drawer->notes()->moveIn([$third], expected: null, placement: Placement::first());
        $first->move(Placement::last(), expected: null);
        $drawer->notes()->moveIn([$second], expected: null, placement: Placement::after($third->id));

        $this->assertSame([$third->id, $second->id, $first->id], DB::table('notes')->where('drawer_id', $drawer->id)->orderBy('rank_key')->pluck('id')->all());
        $this->assertSame([null, null, null], [$third->position, $first->position, $second->position]);
        $this->assertSame([null, null, null], Note::query()->whereKey([$first->id, $second->id, $third->id])->get()->pluck('position')->all());

        $placed = Note::query()->findOrFail($third->id);
        $drawer->notes()->moveIn([$placed], expected: null, placement: Placement::first());

        $this->assertNull($placed->position);
    }

    #[Test]
    public function it_brings_a_stale_member_up_to_date_when_a_move_in_finds_it_already_in_place(): void
    {
        [$source, $destination] = [Section::create(), Section::create()];
        $resident = Asset::create(['section_id' => $destination->id]);
        $stale = Asset::create(['section_id' => $source->id]);
        $stale->setRelation('section', $source);
        $destination->assets()->moveIn([Asset::query()->findOrFail($stale->id)], expected: null);
        $stored = DB::table('assets')->where('id', $stale->id)->first();
        $stale->title = 'unsaved';
        $events = [];
        Event::listen(Reordered::class, function (Reordered $event) use (&$events): void {
            $events[] = $event;
        });
        DB::enableQueryLog();

        $change = $destination->assets()->moveIn([$stale], expected: null);

        $this->assertSame([], $change->changed);
        $this->assertSame([$resident->id, $stale->id], $change->order);
        $this->assertSame([], $events);
        $this->assertSame([], array_filter(DB::getQueryLog(), fn (array $statement): bool => str_starts_with(strtolower($statement['query']), 'update')));
        $this->assertSame($destination->id, $stale->section_id);
        $this->assertSame($stored->rank_key, $stale->rank_key);
        $this->assertSame(2, $stale->position);
        $this->assertSame($destination, $stale->getRelation('section'));
        $this->assertSame(['title' => 'unsaved'], $stale->getDirty());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_refuses_a_stale_move_in_and_changes_nothing(Shape $shape): void
    {
        [$source, [$moved]] = $shape->with(1);
        [$destination] = $shape->with(1);
        $stale = $shape->digest($destination);
        $shape->add($destination);
        $before = $shape->rankKeys($source);

        $this->assertRejected($shape, StaleConcurrencyRevision::class, $destination, fn (): mixed => $shape->relation($destination)->moveIn([$moved], expected: $stale));
        $this->assertSame($before, $shape->rankKeys($source));

        $change = $shape->relation($destination)->moveIn([$moved], expected: $shape->digest($destination));

        $this->assertSame($moved->getKey(), $change->order[2]);
    }

    #[Test]
    public function it_refuses_a_move_in_with_an_expected_revision_of_a_member_read_from_a_parent_it_has_since_left(): void
    {
        [$read, $stored, $destination] = [Section::create(), Section::create(), Section::create()];
        $asset = Asset::create(['section_id' => $read->id]);
        DB::table('assets')->where('id', $asset->id)->update(['section_id' => $stored->id]);
        $expected = $destination->concurrencyRevision(SectionRevision::AssetsOrder);

        try {
            $destination->assets()->moveIn([$asset], expected: $expected);
            $this->fail('A move in of a member read from a parent it has since left passed its expected revision.');
        } catch (StaleConcurrencyRevision) {
        }

        $this->assertSame([$asset->id], $stored->assets()->pluck('id')->all());

        $change = $destination->assets()->moveIn([$asset], expected: null);

        $this->assertSame($stored->id, $change->source?->parent->getKey());
        $this->assertSame([$asset->id], $destination->assets()->pluck('id')->all());
    }

    #[Test]
    public function it_refuses_a_move_in_of_an_unsaved_or_repeated_member_or_a_placement_it_cannot_take_before_it_writes(): void
    {
        [$source, $destination] = [Section::create(), Section::create()];
        $asset = Asset::create(['section_id' => $source->id]);
        $video = (new Video)->section()->associate($source);
        $video->save();
        $before = DB::table('assets')->orderBy('id')->get()->all();

        foreach ([
            sprintf('[%s] must be saved before it moves.', Asset::class) => fn (): mixed => $destination->assets()->moveIn([new Asset], expected: null),
            sprintf('[%s] is not ordered in [%s].', Video::class, Section::class) => fn (): mixed => $destination->assets()->moveIn([$video], expected: null),
            sprintf('[%s:%s] is named more than once.', Asset::class, $asset->id) => fn (): mixed => $destination->assets()->moveIn([$asset, Asset::query()->findOrFail($asset->id)], expected: null),
            'Name the destination of a move in by its relation, not in the placement.' => fn (): mixed => $destination->assets()->moveIn([$asset], expected: null, placement: Placement::last()->in($source)),
            sprintf('[%s] must be saved before it orders a member.', Section::class) => fn (): mixed => (new Section)->assets()->moveIn([$asset], expected: null),
        ] as $message => $move) {
            try {
                $move();
                $this->fail('A move in that it cannot take succeeded.');
            } catch (LogicException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }

        try {
            $destination->assets()->moveIn([$asset], expected: null, placement: Placement::after($asset->id));
            $this->fail('A move in placed a member beside itself.');
        } catch (InvalidAnchor) {
        }

        $this->assertEquals($before, DB::table('assets')->orderBy('id')->get()->all());
    }

    #[Test]
    public function it_locks_the_ordering_parents_of_every_source_and_of_the_destination_before_the_parents(): void
    {
        [$first, $second, $third] = [Shelf::create(), Shelf::create(), Shelf::create()];
        [$left, $right, $destination] = array_map(fn (Shelf $shelf): Binder => Binder::create(['shelf_id' => $shelf->id, 'kind' => 'audio']), [$first, $second, $third]);
        $pages = array_map(fn (Binder $binder): Page => Page::create(['binder_id' => $binder->id, 'kind' => 'audio']), [$left, $left, $right]);
        DB::enableQueryLog();

        $change = $destination->pages()->moveIn([$pages[2], $pages[0]], expected: null);

        $this->assertSame([$pages[2]->id, $pages[0]->id], $change->order);
        $this->assertSame(
            [['shelves', $first->id], ['shelves', $second->id], ['shelves', $third->id], ['binders', $left->id], ['binders', $right->id], ['binders', $destination->id]],
            array_slice($this->parentLocks(DB::getQueryLog()), 0, 6),
        );
    }

    #[Test]
    public function it_locks_again_when_a_member_moves_to_another_parent_between_the_read_and_the_lock(): void
    {
        $racer = $this->racer();
        config()->set('database.connections.mover', config('database.connections.pgsql'));
        [$read, $stored, $destination] = [Section::on('racer')->create(), Section::on('racer')->create(), Section::on('racer')->create()];
        $asset = Asset::on('racer')->create(['section_id' => $read->id]);
        $moving = Asset::on('racer')->findOrFail($asset->id);
        $moved = false;

        try {
            DB::listen(function (QueryExecuted $query) use (&$moved, $asset, $stored): void {
                if ($moved || $query->connectionName !== 'racer' || ! str_contains($query->sql, 'from "sections"') || ! str_ends_with($query->sql, 'for no key update')) {
                    return;
                }

                $moved = true;
                DB::connection('mover')->statement("set lock_timeout = '2s'");
                DB::connection('mover')->table('assets')->where('id', $asset->id)->update(['section_id' => $stored->id]);
            });
            $racer->enableQueryLog();

            $change = Section::on('racer')->findOrFail($destination->id)->assets()->moveIn([$moving], expected: null);

            $locked = array_values(array_filter(array_map(
                fn (array $statement): mixed => preg_match('/ from "sections" .*for no key update$/', $statement['query']) === 1 ? $statement['bindings'][0] : null,
                $racer->getQueryLog(),
            ), fn (mixed $id): bool => $id !== null));

            $this->assertTrue($moved, 'The member never moved while the move in locked its parents.');
            $this->assertSame($stored->id, $change->source?->parent->getKey());
            $this->assertSame([$asset->id], $racer->table('assets')->where('section_id', $destination->id)->pluck('id')->all());
            $this->assertSame([$read->id, $destination->id], array_slice($locked, 0, 2));
            $this->assertContains($stored->id, $locked);
        } finally {
            $racer->table('assets')->where('id', $asset->id)->delete();
            $racer->table('sections')->whereIn('id', [$read->id, $stored->id, $destination->id])->delete();
        }
    }

    #[Test]
    public function it_finishes_two_opposite_move_ins_between_two_parents_without_a_deadlock(): void
    {
        $racer = $this->racer();
        [$left, $right] = [Drawer::on('racer')->create(), Drawer::on('racer')->create()];
        $lefts = [$this->addNote($left, 'racer'), $this->addNote($left, 'racer')];
        $rights = [$this->addNote($right, 'racer')];
        $ids = fn (array $notes): array => array_map(fn (Note $note): int => $note->id, $notes);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('drawers')->where('id', $left->id)->lockForUpdate()->first();

            $rivals[] = $this->startRival('move-in.php', (string) $left->id, implode(',', $ids($rights)));
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startRival('move-in.php', (string) $right->id, implode(',', $ids($lefts)));
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $outputs = array_map(fn (InvokedProcess $rival): mixed => json_decode($rival->wait()->output(), true), $rivals);

            $this->assertSame([['moved', $ids($rights)], ['moved', $ids($lefts)]], $outputs, implode(PHP_EOL, array_map(fn (InvokedProcess $rival): string => $rival->errorOutput(), $rivals)));
            $this->assertSame($ids($rights), $racer->table('notes')->where('drawer_id', $left->id)->orderBy('rank_key')->pluck('id')->all());
            $this->assertSame($ids($lefts), $racer->table('notes')->where('drawer_id', $right->id)->orderBy('rank_key')->pluck('id')->all());
        } finally {
            if ($racer->transactionLevel() > 0) {
                $racer->rollBack();
            }

            foreach ($rivals as $rival) {
                $rival->wait();
            }

            $racer->table('notes')->whereIn('drawer_id', [$left->id, $right->id])->delete();
            $racer->table('drawers')->whereIn('id', [$left->id, $right->id])->delete();
        }
    }

    #[Test]
    public function it_finishes_a_locked_move_in_out_of_a_parent_and_its_delete_beside_an_opposite_move_in_without_a_deadlock(): void
    {
        $racer = $this->racer();
        [$emptied, $other] = [Drawer::on('racer')->create(), Drawer::on('racer')->create()];
        $leaving = [$this->addNote($emptied, 'racer'), $this->addNote($emptied, 'racer')];
        $staying = [$this->addNote($other, 'racer')];
        $ids = fn (array $notes): array => array_map(fn (Note $note): int => $note->id, $notes);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('drawers')->where('id', $emptied->id)->lockForUpdate()->first();

            $rivals[] = $this->startRival('move-in.php', (string) $other->id, 'empty', (string) $emptied->id);
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startRival('move-in.php', (string) $emptied->id, implode(',', $ids($staying)));
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $outputs = array_map(fn (InvokedProcess $rival): mixed => json_decode($rival->wait()->output(), true), $rivals);

            $this->assertSame([['moved', $ids($leaving)], ['unavailable', 'trashed']], $outputs, implode(PHP_EOL, array_map(fn (InvokedProcess $rival): string => $rival->errorOutput(), $rivals)));
            $this->assertNotNull($racer->table('drawers')->where('id', $emptied->id)->value('deleted_at'));
            $this->assertSame([...$ids($staying), ...$ids($leaving)], $racer->table('notes')->where('drawer_id', $other->id)->orderBy('rank_key')->pluck('id')->all());
        } finally {
            if ($racer->transactionLevel() > 0) {
                $racer->rollBack();
            }

            foreach ($rivals as $rival) {
                $rival->wait();
            }

            $racer->table('notes')->whereIn('drawer_id', [$emptied->id, $other->id])->delete();
            $racer->table('drawers')->whereIn('id', [$emptied->id, $other->id])->delete();
        }
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_creates_the_records_in_order_after_every_member(Shape $shape): void
    {
        [$parent, [$first]] = $shape->with(1);

        $created = $shape->relation($parent)->createInOrder([['visible' => true], ['visible' => false], ['visible' => true]]);

        $members = $shape->members($parent);
        $this->assertCount(3, $created);
        $this->assertCount(4, $members);
        $this->assertSame($first->getKey(), $members[0]);
        $this->assertSame(array_slice($members, 1), $this->createdMembers($shape, $parent, $created->all()));
        $this->assertSame(array_combine($members, [1, 2, 3, 4]), $shape->positions($parent));
    }

    #[Test]
    public function it_refuses_to_create_in_order_in_a_trashed_or_missing_parent_and_creates_nothing(): void
    {
        [$trashed, $missing] = [Drawer::create(), Drawer::create()];
        $this->addNote($trashed, 'pgsql');
        $trashed->delete();
        DB::table('drawers')->where('id', $missing->id)->delete();
        $before = DB::table('notes')->orderBy('id')->get()->all();

        foreach ([[ParentUnavailability::Trashed, $trashed], [ParentUnavailability::Missing, $missing]] as [$reason, $parent]) {
            try {
                $parent->notes()->createInOrder([[], []]);
                $this->fail('A create in order placed members in a parent that takes none.');
            } catch (UnavailableParent $exception) {
                $this->assertSame($parent, $exception->parent);
                $this->assertSame($reason, $exception->reason);
            }
        }

        $this->assertEquals($before, DB::table('notes')->orderBy('id')->get()->all());
    }

    #[Test]
    public function it_creates_nothing_in_order_from_no_records_without_a_query_even_in_a_trashed_parent(): void
    {
        $trashed = Drawer::create();
        $trashed->delete();
        $this->recordReorders();
        $statements = 0;
        DB::listen(function () use (&$statements): void {
            $statements++;
        });
        Event::listen(TransactionBeginning::class, function () use (&$statements): void {
            $statements++;
        });

        $created = $trashed->notes()->createInOrder([]);

        $this->assertCount(0, $created);
        $this->assertSame(0, $statements);
        $this->assertSame([], $this->reorders);
    }

    #[Test]
    public function it_places_a_create_that_a_listener_makes_during_a_create_in_order_at_its_own_placement(): void
    {
        $drawer = Drawer::create();
        $first = $this->addNote($drawer, 'pgsql');
        $listened = [];
        Note::created(function () use ($drawer, &$listened): void {
            if ($listened === []) {
                $listened[] = $note = $drawer->notes()->make()->placeAt(Placement::first());
                $note->save();
            }
        });
        $this->recordReorders();

        [$one, $two] = $this->createNotesInOrder($drawer, 2);

        $this->assertCount(1, $listened);
        $this->assertSame([$listened[0]->id, $first->id, $one, $two], $this->noteIds($drawer));
        $this->assertSame([
            [$drawer->id, [[$listened[0]->id, 1, null]]],
            [$drawer->id, [[$one, 3, null], [$two, 4, null]]],
        ], $this->reorders);
    }

    #[Test]
    public function it_reports_a_nested_create_in_order_in_its_own_event(): void
    {
        $drawer = Drawer::create();
        $first = $this->addNote($drawer, 'pgsql');
        $nested = null;
        Note::created(function () use ($drawer, &$nested): void {
            if ($nested === null) {
                $nested = [];
                $nested = $this->createNotesInOrder($drawer, 2);
            }
        });
        $this->recordReorders();

        [$outerOne, $outerTwo] = $this->createNotesInOrder($drawer, 2);

        $this->assertIsArray($nested);
        $this->assertCount(2, $nested);
        [$innerOne, $innerTwo] = $nested;
        $this->assertSame([$first->id, $outerOne, $innerOne, $innerTwo, $outerTwo], $this->noteIds($drawer));
        $this->assertSame([
            [$drawer->id, [[$innerOne, 3, null], [$innerTwo, 4, null]]],
            [$drawer->id, [[$outerOne, 2, null], [$outerTwo, 5, null]]],
        ], $this->reorders);
    }

    #[Test]
    public function it_creates_in_order_after_a_listener_moves_a_member_to_the_end(): void
    {
        $drawer = Drawer::create();
        $first = $this->addNote($drawer, 'pgsql');
        $moved = false;
        Note::created(function () use ($first, &$moved): void {
            if (! $moved) {
                $moved = true;
                $first->move(Placement::last(), expected: null);
            }
        });
        $this->recordReorders();

        [$one, $two] = $this->createNotesInOrder($drawer, 2);

        $this->assertSame([$one, $first->id, $two], $this->noteIds($drawer));
        $this->assertSame([
            [$drawer->id, [[$first->id, 2, 1]]],
            [$drawer->id, [[$one, 1, null], [$two, 3, null]]],
        ], $this->reorders);
    }

    #[Test]
    public function it_touches_each_member_of_a_move_in_by_its_own_timestamps_setting(): void
    {
        [$source, $destination] = [Drawer::create(), Drawer::create()];
        $notes = [$this->addNote($source, 'pgsql'), $this->addNote($source, 'pgsql'), $this->addNote($source, 'pgsql'), $this->addNote($source, 'pgsql')];
        $notes[1]->timestamps = false;
        $notes[2]->timestamps = false;
        $stamps = fn (): array => array_map(fn (Note $note): mixed => DB::table('notes')->where('id', $note->id)->value('updated_at'), $notes);
        $before = $stamps();
        $this->travel(1)->days();

        $destination->notes()->moveIn([$notes[0], $notes[1]], expected: null);
        $destination->notes()->moveIn([$notes[2], $notes[3]], expected: null);

        $after = $stamps();
        $this->assertSame(array_map(fn (Note $note): int => $note->id, $notes), $this->noteIds($destination));
        $this->assertSame([false, true, true, false], array_map(fn (mixed $stamp, int $index): bool => $stamp === $before[$index], $after, array_keys($after)));
    }

    #[Test]
    public function it_moves_in_after_a_listener_appends_a_member_while_the_first_member_saves(): void
    {
        [$source, $destination] = [Drawer::create(), Drawer::create()];
        $resident = $this->addNote($destination, 'pgsql');
        [$one, $two] = [$this->addNote($source, 'pgsql'), $this->addNote($source, 'pgsql')];
        $appended = null;
        Note::updated(function (Note $note) use ($destination, $one, &$appended): void {
            if ($appended === null && $note->id === $one->id) {
                $appended = $this->addNote($destination, 'pgsql');
            }
        });
        $this->recordReorders();

        $change = $destination->notes()->moveIn([$one, $two], expected: null);

        $this->assertInstanceOf(Note::class, $appended);
        $this->assertSame([$resident->id, $one->id, $appended->id, $two->id], $this->noteIds($destination));
        $this->assertSame($this->noteIds($destination), $change->order);
        $this->assertSame([2, 4], [$one->position, $two->position]);
        $this->assertSame([
            [$destination->id, [[$appended->id, 3, null]]],
            [$source->id, [[$one->id, null, 1], [$two->id, null, 2]]],
            [$destination->id, [[$one->id, 2, null], [$two->id, 4, null]]],
        ], $this->reorders);
    }

    #[Test]
    public function it_rearranges_members_after_a_listener_appends_a_member_while_the_first_member_saves(): void
    {
        $drawer = Drawer::create();
        [$one, $two, $resident] = [$this->addNote($drawer, 'pgsql'), $this->addNote($drawer, 'pgsql'), $this->addNote($drawer, 'pgsql')];
        $appended = null;
        Note::updated(function (Note $note) use ($drawer, $one, &$appended): void {
            if ($appended === null && $note->id === $one->id) {
                $appended = $this->addNote($drawer, 'pgsql');
            }
        });
        $this->recordReorders();

        $change = $drawer->notes()->moveIn([$one, $two], expected: null);

        $this->assertInstanceOf(Note::class, $appended);
        $this->assertSame([$resident->id, $one->id, $appended->id, $two->id], $this->noteIds($drawer));
        $this->assertSame($this->noteIds($drawer), $change->order);
        $this->assertSame([
            [$drawer->id, [[$appended->id, 3, null]]],
            [$drawer->id, [[$one->id, 2, 1], [$two->id, 4, 2]]],
        ], $this->reorders);
    }

    #[Test]
    public function it_leaves_a_pivot_attached_on_another_connection_during_a_create_in_order_to_its_own_order(): void
    {
        $this->withElsewhere(function (): void {
            $listing = Listing::create();
            $elsewhere = Listing::on('elsewhere')->forceCreate(['id' => $listing->id]);
            new ListingCustomer;
            $attached = false;
            ListingCustomer::creating(function (ListingCustomer $pivot) use ($elsewhere, &$attached): void {
                if (! $attached) {
                    $attached = true;
                    Customer::on('elsewhere')->forceCreate(['id' => $pivot->customer_id]);
                    $elsewhere->customers()->attach($pivot->customer_id);
                }
            });
            $events = [];
            Event::listen(Reordered::class, function (Reordered $event) use (&$events): void {
                $events[] = [$event->change->parent->getConnection()->getName(), array_map(fn (PositionChange $changed): array => [$changed->id, $changed->position], $event->change->changed)];
            });

            $listing->customers()->createInOrder([[], []]);

            $pivots = DB::table('listing_customer')->where('listing_id', $listing->id)->orderBy('rank_key')->pluck('id')->all();
            $attachedPivot = DB::connection('elsewhere')->table('listing_customer')->value('id');
            $this->assertCount(2, $pivots);
            $this->assertSame([
                ['elsewhere', [[$attachedPivot, 1]]],
                ['pgsql', [[$pivots[0], 1], [$pivots[1], 2]]],
            ], $events);
        });
    }

    #[Test]
    public function it_places_a_create_that_a_creating_listener_makes_after_the_create_it_interrupts(): void
    {
        $drawer = Drawer::create();
        $first = $this->addNote($drawer, 'pgsql');
        $inner = null;
        Note::creating(function () use ($drawer, &$inner): void {
            if ($inner === null) {
                $inner = false;
                $inner = $this->addNote($drawer, 'pgsql');
            }
        });
        $this->recordReorders();

        $outer = $this->addNote($drawer, 'pgsql');

        $this->assertInstanceOf(Note::class, $inner);
        $this->assertSame([$first->id, $outer->id, $inner->id], $this->noteIds($drawer));
        $this->assertSame([
            [$drawer->id, [[$inner->id, 2, null]]],
            [$drawer->id, [[$outer->id, 2, null]]],
        ], $this->reorders);
    }

    #[Test]
    public function it_reports_a_create_in_order_that_a_creating_listener_nests_in_its_own_event(): void
    {
        $drawer = Drawer::create();
        $first = $this->addNote($drawer, 'pgsql');
        $nested = null;
        Note::creating(function () use ($drawer, &$nested): void {
            if ($nested === null) {
                $nested = [];
                $nested = $this->createNotesInOrder($drawer, 2);
            }
        });
        $this->recordReorders();

        [$outerOne, $outerTwo] = $this->createNotesInOrder($drawer, 2);

        $this->assertIsArray($nested);
        $this->assertCount(2, $nested);
        [$innerOne, $innerTwo] = $nested;
        $this->assertSame([$first->id, $outerOne, $innerOne, $innerTwo, $outerTwo], $this->noteIds($drawer));
        $this->assertSame([
            [$drawer->id, [[$innerOne, 2, null], [$innerTwo, 3, null]]],
            [$drawer->id, [[$outerOne, 2, null], [$outerTwo, 5, null]]],
        ], $this->reorders);
    }

    #[Test]
    public function it_places_a_single_create_after_a_create_in_order_that_failed_midway(): void
    {
        $drawer = Drawer::create();
        $first = $this->addNote($drawer, 'pgsql');
        $creates = 0;
        Note::creating(function () use (&$creates): void {
            throw_if(++$creates === 2, RuntimeException::class, 'The second record fails.');
        });

        try {
            $drawer->notes()->createInOrder([[], [], []]);
            $this->fail('A create in order that failed midway returned.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The second record fails.', $exception->getMessage());
        }

        $this->assertSame([$first->id], $this->noteIds($drawer));
        $this->recordReorders();

        $single = $drawer->notes()->create();

        $this->assertSame([$first->id, $single->id], $this->noteIds($drawer));
        $this->assertSame(2, $single->position);
        $this->assertSame([[$drawer->id, [[$single->id, 2, null]]]], $this->reorders);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_moves_members_of_several_parents_in_against_the_destination_digest(Shape $shape): void
    {
        [$first, [$a0]] = $shape->with(1);
        [$second, [$b0]] = $shape->with(1);
        [$destination] = $shape->with(1);
        $stale = $shape->digest($destination);
        $shape->add($destination);
        $before = [$shape->rankKeys($first), $shape->rankKeys($second)];

        $this->assertRejected($shape, StaleConcurrencyRevision::class, $destination, fn (): mixed => $shape->relation($destination)->moveIn([$a0, $b0], expected: $stale));
        $this->assertSame($before, [$shape->rankKeys($first), $shape->rankKeys($second)]);

        $change = $shape->relation($destination)->moveIn([$b0, $a0], expected: $shape->digest($destination));

        $this->assertSame([$b0->getKey(), $a0->getKey()], array_slice($change->order, 2));
        $this->assertSame($change->order, $shape->members($destination));
        $this->assertSame([[], []], [$shape->members($first), $shape->members($second)]);
        $this->assertNull($change->source);
        $this->assertSame($shape->digest($destination), $change->digest);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_reads_the_order_digest_of_the_parent(Shape $shape): void
    {
        [$parent] = $shape->with(2);

        $digest = $parent->concurrencyRevision($shape->revision());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $digest);
        $this->assertNotSame(substr(hash('sha256', ''), 0, 32), $digest);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_digests_an_empty_relation(Shape $shape): void
    {
        $parent = $shape->createParent();

        $this->assertSame(substr(hash('sha256', ''), 0, 32), $parent->concurrencyRevision($shape->revision()));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_reorders_every_member(Shape $shape): void
    {
        [$parent, [$first, $second, $third, $fourth]] = $shape->with(4);
        $digest = $shape->digest($parent);

        $change = $shape->arrange($parent, [(string) $this->key($third), (string) $this->key($second), (string) $this->key($first), (string) $this->key($fourth)], expected: $digest);

        $this->assertSame([$third->getKey(), $second->getKey(), $first->getKey(), $fourth->getKey()], $shape->members($parent));
        $this->assertSame([$third->getKey(), $second->getKey(), $first->getKey(), $fourth->getKey()], $change->order);
        $this->assertSame([
            ['id' => $this->key($third), 'position' => 1, 'previousPosition' => 3],
            ['id' => $this->key($first), 'position' => 3, 'previousPosition' => 1],
        ], array_map(get_object_vars(...), $change->changed));
        $this->assertSame($parent, $change->parent);
        $this->assertSame($shape->revision(), $change->revision);
        $this->assertNull($change->source);
        $this->assertNotSame($digest, $change->digest);
        $this->assertSame($shape->digest($parent), $change->digest);
        $this->assertSame($change->digest, $parent->concurrencyRevision($shape->revision()));
        $this->assertFalse($parent->isDirty());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_locks_the_parent_before_it_reorders(Shape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);
        DB::enableQueryLog();

        $shape->arrange($parent, [$this->key($second), $this->key($first)], expected: $shape->digest($parent));

        $statements = array_column(DB::getQueryLog(), 'query');
        $lock = array_find_key($statements, fn (string $statement): bool => str_contains($statement, sprintf('from "%s"', $shape->parentTable())) && str_ends_with($statement, 'for no key update'));
        $written = array_find_key($statements, fn (string $statement): bool => str_starts_with($statement, sprintf('update "%s"', $shape->memberTable())));

        $this->assertIsInt($lock, 'The parent row was never locked.');
        $this->assertIsInt($written, 'No member was written.');
        $this->assertLessThan($written, $lock);
    }

    /**
     * @param  Closure(list<int|string>, int|string): list<int|string>  $ids
     */
    #[DataProvider('mismatches')]
    #[Test]
    public function it_rejects_a_reorder_that_does_not_name_each_member_once(Shape $shape, Closure $ids): void
    {
        [$parent, $members] = $shape->with(3);
        [, [$foreign]] = $shape->with(1);
        $keys = array_map(fn (Model $member): int|string => $this->key($member), $members);

        $this->assertRejected($shape, MembershipMismatch::class, $parent, fn (): OrderChange => $shape->arrange($parent, $ids($keys, $this->key($foreign)), expected: $shape->digest($parent)));
        $this->assertSame($keys, $shape->members($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_reorder_that_names_a_trashed_member(Shape $shape): void
    {
        [$parent, [$first, $trashed, $third]] = $shape->with(3);
        $trashed->delete();
        $revision = $shape->digest($parent);

        $this->assertRejected($shape, MembershipMismatch::class, $parent, fn (): OrderChange => $shape->arrange($parent, [$this->key($third), $this->key($trashed), $this->key($first)], expected: $revision));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_stale_reorder_and_changes_nothing(Shape $shape): void
    {
        [$parent, $first, $second, $stale] = $this->withStaleDigest($shape);

        $this->assertRejected($shape, StaleConcurrencyRevision::class, $parent, fn (): OrderChange => $shape->arrange($parent, [$this->key($second), $this->key($first)], expected: $stale));
        $this->assertSame([$first->getKey(), $second->getKey()], $shape->members($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_stale_reorder_that_keeps_the_order(Shape $shape): void
    {
        [$parent, $first, $second, $stale] = $this->withStaleDigest($shape);

        $this->assertRejected($shape, StaleConcurrencyRevision::class, $parent, fn (): OrderChange => $shape->arrange($parent, [$this->key($first), $this->key($second)], expected: $stale));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_reorders_without_a_comparison_when_nothing_is_expected(Shape $shape): void
    {
        [$parent, $first, $second, $stale] = $this->withStaleDigest($shape);

        $change = $shape->arrange($parent, [$this->key($second), $this->key($first)], expected: null);

        $this->assertSame([$second->getKey(), $first->getKey()], $shape->members($parent));
        $this->assertNotSame($stale, $change->digest);
        $this->assertSame($shape->digest($parent), $change->digest);
    }

    /**
     * @param  Closure(list<int|string>): list<int|string>  $reorder
     */
    #[DataProvider('unchangedReorders')]
    #[Test]
    public function it_writes_nothing_for_a_reorder_that_keeps_the_order(Shape $shape, int $count, Closure $reorder, bool $compare): void
    {
        [$parent, $members] = $shape->with($count);
        $revision = $shape->digest($parent);
        $keys = $shape->rankKeys($parent);
        $ids = array_map(fn (Model $member): int|string => $this->key($member), $members);
        DB::enableQueryLog();

        $change = $shape->arrange($parent, $reorder($ids), $compare ? $revision : null);

        $this->assertSame([], $this->writes());
        $this->assertSame([], $change->changed);
        $this->assertSame($revision, $change->digest);
        $this->assertSame($ids, $change->order);
        $this->assertSame($revision, $shape->digest($parent));
        $this->assertSame($keys, $shape->rankKeys($parent));
    }

    #[Test]
    public function it_orders_by_rank_key_over_an_order_its_relation_declares(): void
    {
        $section = Section::create();
        $zebra = $this->addAsset($section, 'Zebra');
        $alpha = $this->addAsset($section, 'Alpha');
        $parent = $this->titleOrderedSection($section);

        $this->assertSame([$alpha->id => 2, $zebra->id => 1], Shape::relationOf($parent, SectionRevision::AssetsOrder)->get()->pluck('position', 'id')->all());

        $unchanged = Shape::relationOf($parent, SectionRevision::AssetsOrder)->arrange([$zebra->id, $alpha->id], expected: null);

        $this->assertSame([], $unchanged->changed);
        $this->assertSame([$zebra->id, $alpha->id], $unchanged->order);

        $change = Shape::relationOf($parent, SectionRevision::AssetsOrder)->arrange([$alpha->id, $zebra->id], expected: null);

        $this->assertSame([$alpha->id, $zebra->id], $change->order);
        $this->assertSame([
            ['id' => $alpha->id, 'position' => 1, 'previousPosition' => 2],
            ['id' => $zebra->id, 'position' => 2, 'previousPosition' => 1],
        ], array_map(get_object_vars(...), $change->changed));
        $this->assertSame([$alpha->id => 1, $zebra->id => 2], Shape::relationOf($parent, SectionRevision::AssetsOrder)->get()->pluck('position', 'id')->all());
    }

    #[Test]
    public function it_keeps_a_row_its_relation_excludes_after_the_member_that_precedes_it(): void
    {
        $section = Section::create();
        $first = $this->addAsset($section, 'Shown');
        $excluded = $this->addAsset($section, 'Excluded');
        $last = $this->addAsset($section, 'Shown');

        $parent = (new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['assets.title'])]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where('title', 'Shown');
            }
        })->newQuery()->findOrFail($section->id);

        $change = Shape::relationOf($parent, SectionRevision::AssetsOrder)->arrange([$last->id, $first->id], expected: null);

        $this->assertSame([$last->id, $first->id], $change->order);
        $this->assertSame([$last->id, $first->id, $excluded->id], DB::table('assets')->where('section_id', $section->id)->orderBy('rank_key')->pluck('id')->all());
    }

    #[Test]
    public function it_reorders_a_trashed_row_that_its_relation_includes(): void
    {
        $section = Section::create();
        $first = $this->addAsset($section, 'Shown');
        $trashed = $this->addAsset($section, 'Shown');
        Asset::query()->whereKey($trashed->id)->delete();

        $parent = (new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder)]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->withTrashed();
            }
        })->newQuery()->findOrFail($section->id);

        $change = Shape::relationOf($parent, SectionRevision::AssetsOrder)->arrange([$trashed->id, $first->id], expected: null);

        $this->assertSame([$trashed->id, $first->id], $change->order);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_reorders_by_upper_case_uuids(PivotShape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);

        $change = $shape->arrange($parent, [strtoupper((string) $this->key($second)), strtoupper((string) $this->key($first))], expected: $shape->digest($parent));

        $this->assertSame([$second->getKey(), $first->getKey()], $shape->members($parent));
        $this->assertSame([$second->getKey(), $first->getKey()], $change->order);
    }

    #[Test]
    public function it_reorders_a_uuid_pivot_that_declares_an_integer_key_type(): void
    {
        $shape = new BelongsToManyShape;
        [$listing, [$first, $second]] = $shape->with(2);
        $parent = $this->parentOfPivot($listing, $this->integerKeyedPivot());

        $change = Shape::relationOf($parent, ListingRevision::CustomersOrder)->arrange([(string) $this->key($second), (string) $this->key($first)], expected: null);

        $this->assertSame([$second->getKey(), $first->getKey()], $shape->members($listing));
        $this->assertSame([$second->getKey(), $first->getKey()], $change->order);
    }

    #[Test]
    public function it_reorders_by_upper_case_uuids_on_a_pivot_that_does_not_use_uuids(): void
    {
        $shape = new BelongsToManyShape;
        [$listing, [$first, $second]] = $shape->with(2);
        $parent = $this->parentOfPivot($listing, $this->stringKeyedPivot());

        $change = Shape::relationOf($parent, ListingRevision::CustomersOrder)->arrange([strtoupper((string) $this->key($second)), strtoupper((string) $this->key($first))], expected: null);

        $this->assertSame([$second->getKey(), $first->getKey()], $shape->members($listing));
        $this->assertSame([$second->getKey(), $first->getKey()], $change->order);
    }

    #[Test]
    public function it_arranges_each_of_two_pivot_relations_to_one_related_class_in_its_own_order(): void
    {
        $shape = new BelongsToManyShape;
        $customers = [Customer::create()->id, Customer::create()->id, Customer::create()->id];
        $listing = Listing::create();
        $agency = Agency::create(['id' => $listing->id]);
        $listing->customers()->attach($customers);
        $agency->customers()->attach($customers);
        $parent = TwoPivotListing::query()->findOrFail($listing->id);
        [$listed, $represented] = [$shape->members($listing), $this->agencyMembers($agency)];

        $listings = Shape::relationOf($parent, ListingRevision::CustomersOrder)->arrange(array_reverse($listed), expected: null);

        $this->assertSame(ListingRevision::CustomersOrder, $listings->revision);
        $this->assertSame(array_reverse($listed), $listings->order);
        $this->assertSame(array_reverse($listed), $shape->members($listing));
        $this->assertSame($represented, $this->agencyMembers($agency));

        $agencies = Shape::relationOf($parent, ListingRevision::AgencyCustomersOrder)->arrange([$represented[1], $represented[2], $represented[0]], expected: null);

        $this->assertSame(ListingRevision::AgencyCustomersOrder, $agencies->revision);
        $this->assertSame([$represented[1], $represented[2], $represented[0]], $agencies->order);
        $this->assertSame([$represented[1], $represented[2], $represented[0]], $this->agencyMembers($agency));
        $this->assertSame(array_reverse($listed), $shape->members($listing));
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_reorders_pivots_on_the_connection_of_their_parent(PivotShape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);
        $default = config()->string('database.default');
        config()->set('database.default', 'sqlite');

        try {
            $change = $shape->arrange($parent, [$this->key($second), $this->key($first)], expected: null);
        } finally {
            config()->set('database.default', $default);
        }

        $this->assertSame([$second->getKey(), $first->getKey()], $shape->members($parent));
        $this->assertSame($shape->digest($parent), $change->digest);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_fires_the_model_events_of_each_member_a_reorder_rewrites_and_none_of_the_parent(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $this->travel(1)->minutes();
        $rows = array_map(ModelEvents::row(...), [$first, $second, $third]);
        $revision = $shape->digest($parent);
        $events = ModelEvents::record();

        $shape->arrange($parent, [$this->key($third), $this->key($second), $this->key($first)], expected: null);

        $this->assertSame($rows[1], ModelEvents::row($second));
        $this->assertSame([
            ...self::savedEvents($third, $rows[2], ModelEvents::row($third)),
            ...self::savedEvents($first, $rows[0], ModelEvents::row($first)),
        ], self::sqlOf($events->fired));
        $this->assertNotSame($revision, $shape->digest($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_fires_the_model_events_of_each_member_a_rebalance_rewrites_and_of_the_moved_member_again_and_none_of_the_parent(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $shape->crowd($first, $second);
        DB::table($shape->memberTable())->where('id', $third->getKey())->update([OrderedRelation::RANK_KEY => 'a5']);
        $this->travel(1)->minutes();
        $rows = array_map(ModelEvents::row(...), [$first, $second, $third]);
        $rebalanced = [...$rows[2], OrderedRelation::RANK_KEY => FractionalIndex::keysBetween(null, null, 3)[2], 'updated_at' => now()->toDateTimeString()];
        $revision = $shape->digest($parent);
        $events = ModelEvents::record();

        $third->move(Placement::after($this->key($first)), expected: null);

        $this->assertLessThan(OrderedRelation::MAX_KEY_LENGTH, $shape->longestRankKey($parent));
        $this->assertSame([
            ...self::savedEvents($first, $rows[0], ModelEvents::row($first)),
            ...self::savedEvents($second, $rows[1], ModelEvents::row($second)),
            ...self::savedEvents($third, $rows[2], $rebalanced),
            ...self::savedEvents($third, $rebalanced, ModelEvents::row($third)),
        ], self::sqlOf($events->fired));
        $this->assertNotSame($revision, $shape->digest($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_advances_the_updated_at_of_each_member_a_reorder_rewrites_and_not_of_the_parent(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $stamps = fn (): array => array_map(fn (Model $model): mixed => ModelEvents::row($model)['updated_at'], [$first, $second, $third, $parent]);
        $before = $stamps();
        $this->travel(1)->minutes();

        $shape->arrange($parent, [$this->key($third), $this->key($second), $this->key($first)], expected: null);

        $this->assertSame([now()->toDateTimeString(), $before[1], now()->toDateTimeString(), $before[3]], $stamps());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_advances_the_updated_at_of_each_member_a_rebalance_rewrites_and_not_of_the_parent(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $shape->crowd($first, $second);
        $stamps = fn (): array => array_map(fn (Model $model): mixed => ModelEvents::row($model)['updated_at'], [$first, $second, $third, $parent]);
        $before = $stamps();
        $this->travel(1)->minutes();

        $shape->add($parent, Placement::after($this->key($first)));

        $this->assertLessThan(OrderedRelation::MAX_KEY_LENGTH, $shape->longestRankKey($parent));
        $this->assertSame([now()->toDateTimeString(), now()->toDateTimeString(), $before[2], $before[3]], $stamps());
    }

    #[Test]
    public function it_announces_only_the_source_for_a_save_into_a_parent_whose_relation_excludes_the_member(): void
    {
        [$audio, [, $moving]] = $this->playlist('audio', ['audio', 'audio']);
        [$video] = $this->playlist('video', ['video']);
        $revisions = [$this->tracksRevision($audio), $this->tracksRevision($video)];
        $this->recordReorders();

        $moving->playlist()->associate($video);

        $this->assertTrue($moving->save());
        $this->assertNull(Track::query()->findOrFail($moving->id)->position);
        $this->assertNull($moving->position);
        $this->assertSame([[$audio->id, [[$moving->id, null, 2]]]], $this->reorders);
        $this->assertNotSame($revisions[0], $this->tracksRevision($audio));
        $this->assertSame($revisions[1], $this->tracksRevision($video));
    }

    #[Test]
    public function it_announces_only_the_source_for_a_move_into_a_parent_whose_relation_excludes_the_member(): void
    {
        [$audio, [, $moving]] = $this->playlist('audio', ['audio', 'audio']);
        [$video, [$held]] = $this->playlist('video', ['video']);
        $revisions = [$this->tracksRevision($audio), $this->tracksRevision($video)];
        $this->recordReorders();

        $change = $moving->move(Placement::last()->in($video), expected: null);

        $this->assertSame([[$audio->id, [[$moving->id, null, 2]]]], $this->reorders);
        $this->assertNotSame($revisions[0], $this->tracksRevision($audio));
        $this->assertSame($revisions[1], $this->tracksRevision($video));
        $this->assertSame($video, $change->parent);
        $this->assertSame([$held->id], $change->order);
        $this->assertSame([[$moving->id, null, null]], array_map(fn (PositionChange $changed): array => [$changed->id, $changed->position, $changed->previousPosition], $change->changed));
        $this->assertSame($audio->id, $change->source?->parent->getKey());
    }

    #[Test]
    public function it_reports_the_rebalance_of_a_parent_whose_relation_excludes_the_member_that_a_move_enters(): void
    {
        [, [$moving]] = $this->playlist('audio', ['audio']);
        [$video, [$first, $second]] = $this->playlist('video', ['video', 'video']);
        $prefix = 'a0'.str_repeat('V', OrderedRelation::MAX_KEY_LENGTH - 3);
        DB::table('tracks')->where('id', $first->id)->update([OrderedRelation::RANK_KEY => $prefix.'V']);
        DB::table('tracks')->where('id', $second->id)->update([OrderedRelation::RANK_KEY => $prefix.'W']);
        $rebalanced = [];

        Event::listen(Rebalanced::class, function (Rebalanced $event) use (&$rebalanced): void {
            $rebalanced[] = [$event->parent->getKey(), $event->ids];
        });

        $moving->move(Placement::after($first->id)->in($video), expected: null);

        $this->assertSame([[$video->id, [$first->id, $second->id]]], $rebalanced);
    }

    /**
     * @param  Closure(): (Model&OrdersRelations&Revisioned)  $parent
     */
    #[DataProvider('uninspectableMemberships')]
    #[DataProvider('uncompilableMemberships')]
    #[Test]
    public function it_refuses_a_relation_whose_membership_filter_it_cannot_verify(Closure $parent, NamesConcurrencyRevision $revision, string $message): void
    {
        try {
            $parent()->concurrencyRevision($revision);
            $this->fail('A relation whose membership filter cannot be verified was resolved.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    #[Test]
    public function it_keeps_the_open_transaction_usable_after_it_refuses_a_subquery_filter(): void
    {
        DB::transaction(function (): void {
            try {
                self::correlatedFolder()->concurrencyRevision(FolderRevision::DocumentsOrder);
                $this->fail('A relation whose subquery filter reads the row it filters was resolved.');
            } catch (LogicException) {
                $this->assertSame(1, DB::scalar('select 1'));
            }
        });
    }

    /**
     * @param  Closure(): (Model&OrdersRelations&Revisioned)  $parent
     */
    #[DataProvider('inspectableMemberships')]
    #[Test]
    public function it_resolves_a_relation_whose_membership_filter_it_verifies(Closure $parent, NamesConcurrencyRevision $revision): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $parent()->concurrencyRevision($revision));
    }

    /**
     * @param  Closure(): (Model&OrdersRelations&Revisioned)  $parent
     * @param  Closure(class-string): string  $message
     */
    #[DataProvider('omittedMembershipColumns')]
    #[DataProvider('omittedColumnClauseColumns')]
    #[DataProvider('omittedOrderedThroughParents')]
    #[DataProvider('unorderableRelationTypes')]
    #[Test]
    public function it_refuses_a_misdeclared_relation_when_it_reads_the_order_digest(Closure $parent, NamesConcurrencyRevision $revision, Closure $message): void
    {
        $declared = $parent();

        try {
            $declared->concurrencyRevision($revision);
            $this->fail('The order digest of a misdeclared relation was read.');
        } catch (LogicException $exception) {
            $this->assertSame($message($declared::class), $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{Closure(): (Model&OrdersRelations&Revisioned), NamesConcurrencyRevision, Closure(class-string): string}>
     */
    public static function omittedMembershipColumns(): iterable
    {
        yield 'a watched related column its membership omits' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this, ListingCustomer>
             */
            #[OrdersRelation(ListingRevision::CustomersOrder)]
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id')
                    ->using(ListingCustomer::class)
                    ->wherePivotNull('deleted_at')
                    ->where('customers.name', NamedListing::NAME);
            }
        }, ListingRevision::CustomersOrder, static fn (string $parent): string => sprintf(
            '[%s::customers()] filters its members by [customers.name], which its membership does not declare.',
            $parent,
        )];
    }

    /**
     * @return iterable<string, array{Closure(): (Model&OrdersRelations&Revisioned), NamesConcurrencyRevision, Closure(class-string): string}>
     */
    public static function omittedColumnClauseColumns(): iterable
    {
        yield 'the second column of a column clause its membership omits' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'folders';

            /**
             * @return HasMany<Document, $this>
             */
            #[OrdersRelation(FolderRevision::DocumentsOrder, membership: ['documents.kind'])]
            public function documents(): HasMany
            {
                return $this->hasMany(Document::class, 'folder_id')->whereColumn('documents.kind', '!=', 'documents.locale');
            }
        }, FolderRevision::DocumentsOrder, static fn (string $parent): string => sprintf(
            '[%s::documents()] filters its members by [documents.locale], which its membership does not declare.',
            $parent,
        )];
    }

    /**
     * @return iterable<string, array{Closure(): (Model&OrdersRelations&Revisioned), NamesConcurrencyRevision, Closure(class-string): string}>
     */
    public static function omittedOrderedThroughParents(): iterable
    {
        yield 'a related model that omits the parent from #[OrderedThrough]' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this, ListingCustomer>
             */
            #[OrdersRelation(ListingRevision::CustomersOrder)]
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id')
                    ->using(ListingCustomer::class)
                    ->wherePivotNull('deleted_at');
            }
        }, ListingRevision::CustomersOrder, static fn (string $parent): string => sprintf(
            '[%1$s::customers()] orders the pivots of [%2$s], so [%2$s] must name [%1$s] in #[%3$s].',
            $parent,
            Customer::class,
            OrderedThrough::class,
        )];
    }

    /**
     * @return iterable<string, array{Closure(): (Model&OrdersRelations&Revisioned), NamesConcurrencyRevision, Closure(class-string): string}>
     */
    public static function unorderableRelationTypes(): iterable
    {
        yield 'a HasOne method' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasOne<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder)]
            public function assets(): HasOne
            {
                return $this->hasOne(Asset::class, 'section_id');
            }
        }, SectionRevision::AssetsOrder, static fn (string $parent): string => sprintf(
            '[%s::assets()] must return a HasMany, MorphMany, BelongsToMany, or MorphToMany relation to an Ordered model.',
            $parent,
        )];
    }

    /**
     * @return iterable<string, array{Closure(): (Model&OrdersRelations&Revisioned), NamesConcurrencyRevision, string}>
     */
    public static function uninspectableMemberships(): iterable
    {
        yield 'a raw clause' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder)]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->whereRaw('"assets"."title" = ?', ['Shown']);
            }
        }, SectionRevision::AssetsOrder, '::assets()] filters its members through a clause of type [raw]'];

        yield 'an exists clause' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder)]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->whereExists(static function (Builder $query): void {
                    $query->from('videos')->whereColumn('videos.section_id', 'assets.section_id');
                });
            }
        }, SectionRevision::AssetsOrder, '::assets()] filters its members through a clause of type [Exists]'];

        yield 'an expression column' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder)]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where(new Expression('lower("assets"."title")'), 'shown');
            }
        }, SectionRevision::AssetsOrder, '::assets()] filters its members by an expression'];

        yield 'a top-level or' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder)]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->orWhere('assets.title', 'Shown');
            }
        }, SectionRevision::AssetsOrder, '::assets()] joins a filter to its members with [or]'];

        yield 'an undeclared item column' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder)]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where('title', 'Shown');
            }
        }, SectionRevision::AssetsOrder, '::assets()] filters its members by [assets.title], which its membership does not declare.'];

        yield 'an undeclared parent column' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['assets.title'])]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where('assets.title', $this->getAttribute('name'));
            }
        }, SectionRevision::AssetsOrder, '::assets()] filters its members by [sections.name], which its membership does not declare.'];

        yield 'an unqualified pivot column' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this, ListingCustomer>
             */
            #[OrdersRelation(ListingRevision::CustomersOrder)]
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id')->using(ListingCustomer::class)->where('title', 'Shown');
            }
        }, ListingRevision::CustomersOrder, '::customers()] filters its members by the unqualified column [title]'];

        yield 'an unqualified declaration' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['title'])]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where('assets.title', 'Shown');
            }
        }, SectionRevision::AssetsOrder, '::assets()] declares the membership column [title], which names no table of the relation.'];

        yield 'a declaration on another table' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['videos.title'])]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id');
            }
        }, SectionRevision::AssetsOrder, '::assets()] declares the membership column [videos.title], which names no table of the relation.'];

        yield 'an automatic item timestamp' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['assets.updated_at'])]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id');
            }
        }, SectionRevision::AssetsOrder, '::assets()] declares the membership column [assets.updated_at], which Eloquent sets on a save of [Tests\Fixtures\Support\Assets\Asset].'];

        yield 'an automatic parent timestamp' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['sections.created_at'])]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id');
            }
        }, SectionRevision::AssetsOrder, '::assets()] declares the membership column [sections.created_at], which Eloquent sets on a save of [Illuminate\Database\Eloquent\Model@anonymous'];

        yield 'an automatic related timestamp' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this, ListingCustomer>
             */
            #[OrdersRelation(ListingRevision::CustomersOrder, membership: ['customers.updated_at'])]
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id')->using(ListingCustomer::class);
            }
        }, ListingRevision::CustomersOrder, '::customers()] declares the membership column [customers.updated_at], which Eloquent sets on a save of [Tests\Fixtures\Support\Customers\Customer].'];
    }

    /**
     * @return iterable<string, array{Closure(): (Model&OrdersRelations&Revisioned), NamesConcurrencyRevision, string}>
     */
    public static function uncompilableMemberships(): iterable
    {
        yield 'an expression value' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['assets.title'])]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where('assets.title', new Expression('"assets"."visible"'));
            }
        }, SectionRevision::AssetsOrder, '::assets()] compares its members by [assets.title] to an expression, which a position query cannot re-alias. Compare two columns with whereColumn().'];

        yield 'a nested expression value' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['assets.title'])]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where(static function (EloquentBuilder $query): void {
                    $query->where('assets.title', '!=', new Expression('"assets"."visible"'));
                });
            }
        }, SectionRevision::AssetsOrder, '::assets()] compares its members by [assets.title] to an expression'];

        yield 'a parent column compared by another operator' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['assets.title', 'sections.name'])]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where('assets.title', '!=', $this->getAttribute('name'));
            }
        }, SectionRevision::AssetsOrder, '::assets()] filters its members by the parent column [sections.name] in a form other than [<item column> = sections.name]'];

        yield 'a parent column in a list' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['assets.title', 'sections.name'])]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->whereIn('assets.title', [$this->getAttribute('name'), 'Shown']);
            }
        }, SectionRevision::AssetsOrder, '::assets()] filters its members by the parent column [sections.name] in a form other than [<item column> = sections.name]'];

        yield 'a parent column read through an accessor that parses it' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'folders';

            /**
             * @return HasMany<KindedDocument, $this>
             */
            #[OrdersRelation(FolderRevision::DocumentsOrder, membership: ['documents.kind', 'folders.kind'])]
            public function documents(): HasMany
            {
                return $this->hasMany(KindedDocument::class, 'folder_id')->where('documents.kind', $this->getAttribute('kind'));
            }

            /**
             * @return Attribute<null|DocumentKind, never>
             */
            public function kind(): Attribute
            {
                return Attribute::make(get: static fn (mixed $value): null|DocumentKind => is_string($value) ? DocumentKind::from($value) : null);
            }
        }, FolderRevision::DocumentsOrder, '::documents()] filters its members by the parent column [folders.kind] in a form other than [<item column> = folders.kind]'];

        yield 'a parent column with a fallback for a null value' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'playlists';

            /**
             * @return HasMany<Track, $this>
             */
            #[OrdersRelation(PlaylistRevision::TracksOrder, membership: ['tracks.kind', 'playlists.kind'])]
            public function tracks(): HasMany
            {
                return $this->hasMany(Track::class, 'playlist_id')->where('tracks.kind', $this->getAttribute('kind') ?? 'audio');
            }
        }, PlaylistRevision::TracksOrder, '::tracks()] filters its members by the parent column [playlists.kind] in a form other than [<item column> = playlists.kind]'];

        yield 'a parent column compared only when it is set' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'playlists';

            /**
             * @return HasMany<Track, $this>
             */
            #[OrdersRelation(PlaylistRevision::TracksOrder, membership: ['tracks.kind', 'playlists.kind'])]
            public function tracks(): HasMany
            {
                $tracks = $this->hasMany(Track::class, 'playlist_id');

                if ($this->getAttribute('kind') !== null) {
                    $tracks->where('tracks.kind', $this->getAttribute('kind'));
                }

                return $tracks;
            }
        }, PlaylistRevision::TracksOrder, '::tracks()] filters its members by the parent column [playlists.kind] in a form other than [<item column> = playlists.kind]'];

        yield 'a subquery that reads the row it filters' => [self::correlatedFolder(...), FolderRevision::DocumentsOrder, '::documents()] filters its members through a subquery that reads a table outside it'];

        yield 'a joined table' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'folders';

            /**
             * @return HasMany<KindedDocument, $this>
             */
            #[OrdersRelation(FolderRevision::DocumentsOrder)]
            public function documents(): HasMany
            {
                return $this->hasMany(KindedDocument::class, 'folder_id')
                    ->join('folders as homes', static fn (JoinClause $join): JoinClause => $join->on('homes.id', '=', 'documents.home_folder_id')->where('homes.name', '=', 'Archive'))
                    ->select('documents.*');
            }
        }, FolderRevision::DocumentsOrder, '::documents()] joins [folders as homes] to its members, so a position query cannot reproduce the join for each row.'];

        yield 'a join beside the pivot table' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this, ListingCustomer>
             */
            #[OrdersRelation(ListingRevision::CustomersOrder)]
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id')
                    ->using(ListingCustomer::class)
                    ->join('customers as kept', 'kept.id', '=', 'customers.id');
            }
        }, ListingRevision::CustomersOrder, '::customers()] joins [customers as kept] to its members, so a position query cannot reproduce the join for each row.'];
    }

    /**
     * @return iterable<string, array{Closure(): (Model&OrdersRelations&Revisioned), NamesConcurrencyRevision}>
     */
    public static function inspectableMemberships(): iterable
    {
        yield 'basic, null and in clauses on declared item columns' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['assets.title', 'assets.visible'])]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')
                    ->where('title', 'Shown')
                    ->whereNotNull('assets.title')
                    ->whereIn('assets.visible', [true]);
            }
        }, SectionRevision::AssetsOrder];

        yield 'a column clause on two declared item columns' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'folders';

            /**
             * @return HasMany<Document, $this>
             */
            #[OrdersRelation(FolderRevision::DocumentsOrder, membership: ['documents.kind', 'documents.locale'])]
            public function documents(): HasMany
            {
                return $this->hasMany(Document::class, 'folder_id')->whereColumn('documents.kind', '!=', 'documents.locale');
            }
        }, FolderRevision::DocumentsOrder];

        yield 'an or inside a nested clause' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['assets.title'])]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where(static function (EloquentBuilder $query): void {
                    $query->where('assets.title', 'Shown')->orWhereNull('assets.title');
                });
            }
        }, SectionRevision::AssetsOrder];

        yield 'a declared parent column' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['assets.title', 'sections.name'])]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where('assets.title', $this->getAttribute('name'));
            }
        }, SectionRevision::AssetsOrder];

        yield 'a declared pivot column' => [static fn (): Model&OrdersRelations&Revisioned => new TitledListing, ListingRevision::CustomersOrder];

        yield 'a declared related column' => [static fn (): Model&OrdersRelations&Revisioned => new NamedListing, ListingRevision::CustomersOrder];
    }

    public static function correlatedFolder(): Model&OrdersRelations&Revisioned
    {
        return new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'folders';

            /**
             * @return HasMany<KindedDocument, $this>
             */
            #[OrdersRelation(FolderRevision::DocumentsOrder, membership: ['documents.home_folder_id'])]
            public function documents(): HasMany
            {
                return $this->hasMany(KindedDocument::class, 'folder_id')->whereIn(
                    'documents.home_folder_id',
                    $this->getConnection()->table('folders')->select('folders.id')->whereColumn('folders.kind', 'documents.kind'),
                );
            }
        };
    }

    /**
     * @return array{string, string}
     */
    private static function ascendingUuids(): array
    {
        $ids = [(string) Str::uuid(), (string) Str::uuid()];
        sort($ids);

        return [$ids[0], $ids[1]];
    }

    private static function racerComposedItemIn(ComposedItem $parent, null|string $id = null): ComposedItem
    {
        $item = (new ComposedItem)->setConnection('racer')->forceFill($id === null ? [] : ['id' => $id]);
        $item->parent()->associate($parent);
        $item->save();

        return $item;
    }

    /**
     * @param  list<list<string>>  $starts
     * @return array{list<mixed>, string}
     */
    private function raceBehindLock(Connection $racer, string $table, int|string $id, array $starts): array
    {
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table($table)->where('id', $id)->lockForUpdate()->first();

            foreach ($starts as $started => $arguments) {
                $rivals[] = $this->startRival(...$arguments);
                $this->awaitLockWait($started + 1, ...$rivals);
            }

            $racer->rollBack();

            return [
                array_map(fn (InvokedProcess $rival): mixed => json_decode($rival->wait()->output(), true), $rivals),
                implode(PHP_EOL, array_map(fn (InvokedProcess $rival): string => $rival->errorOutput(), $rivals)),
            ];
        } finally {
            if ($racer->transactionLevel() > 0) {
                $racer->rollBack();
            }

            foreach ($rivals as $rival) {
                $rival->wait();
            }
        }
    }

    /**
     * @param  array<int, array{query: string, bindings: array<int, mixed>}>  $statements
     * @return list<array{string, mixed}>
     */
    private function parentLocks(array $statements, string $tables = 'shelves|binders'): array
    {
        $locks = [];

        foreach ($statements as $statement) {
            if (preg_match('/ from "('.$tables.')" .*for no key update$/', $statement['query'], $match) === 1) {
                $locks[] = [$match[1], $statement['bindings'][0]];
            }
        }

        return $locks;
    }

    /**
     * @param  list<Model>  $created
     * @return list<int|string>
     */
    private function createdMembers(Shape $shape, Model $parent, array $created): array
    {
        return array_map(fn (Model $model): int|string => $this->key($shape instanceof PivotShape && $model instanceof Customer ? $shape->pivotOf($parent, $model) : $model), $created);
    }

    /**
     * @return list<int|string>
     */
    private function createNotesInOrder(Drawer $drawer, int $count): array
    {
        return array_map($this->key(...), $drawer->notes()->createInOrder(array_fill(0, $count, []))->all());
    }

    /**
     * @return list<int>
     */
    private function noteIds(Drawer $drawer): array
    {
        return DB::table('notes')->where('drawer_id', $drawer->id)->whereNull('deleted_at')->orderBy('rank_key')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * @param  Closure(): void  $test
     */
    private function withElsewhere(Closure $test): void
    {
        config()->set('database.connections.elsewhere', [...config()->array('database.connections.pgsql'), 'search_path' => 'elsewhere,public']);
        $connection = DB::connection('elsewhere');
        $connection->statement('drop schema if exists elsewhere cascade');
        $connection->statement('create schema elsewhere');

        try {
            $migrator = $this->app?->make('migrator');
            $this->assertInstanceOf(Migrator::class, $migrator);
            $migrator->usingConnection('elsewhere', function () use ($migrator): void {
                $migrator->getRepository()->createRepository();
                $migrator->run(array_map(fn (string $migration): string => dirname(__DIR__, 5).'/tests/Fixtures/Support/Migrations/'.$migration.'.php', [
                    '2026_10_24_000013_create_listings_table',
                    '2026_10_24_000014_create_customers_table',
                    '2026_10_24_000016_create_listing_customer_table',
                ]));
            });

            $test();
        } finally {
            $connection->statement('drop schema elsewhere cascade');
            DB::purge('elsewhere');
        }
    }

    private function addNote(Drawer $drawer, string $connection): Note
    {
        $note = (new Note)->setConnection($connection);
        $note->drawer()->associate($drawer);
        $note->save();

        return $note;
    }

    /**
     * @return class-string<Pivot&Ordered>
     */
    private function integerKeyedPivot(): string
    {
        return (new class extends Pivot implements Ordered
        {
            use IsOrdered;
            use SoftDeletes;

            protected $table = 'listing_customer';
        })::class;
    }

    /**
     * @return class-string<Pivot&Ordered>
     */
    private function stringKeyedPivot(): string
    {
        return (new class extends Pivot implements Ordered
        {
            use IsOrdered;
            use SoftDeletes;

            protected $table = 'listing_customer';

            protected $keyType = 'string';
        })::class;
    }

    private function titleOrderedSection(Section $section): Model&OrdersRelations&Revisioned
    {
        $parent = (new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            #[OrdersRelation(SectionRevision::AssetsOrder)]
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->orderBy('title');
            }
        })->newQuery()->findOrFail($section->id);
        assert($parent instanceof OrdersRelations && $parent instanceof Revisioned);

        return $parent;
    }

    /**
     * @param  class-string<Pivot&Ordered>  $pivot
     */
    private function parentOfPivot(Model $listing, string $pivot): PivotedListing
    {
        PivotedListing::$pivot = $pivot;

        return PivotedListing::query()->findOrFail($listing->getKey());
    }

    /**
     * @return list<string>
     */
    private function agencyMembers(Agency $agency): array
    {
        return Shape::inOrder($agency, AgencyRevision::CustomersOrder)->get()->map(function (Model $customer): string {
            $key = $customer->getRelation('pivot')?->getKey();
            assert(is_string($key));

            return $key;
        })->values()->all();
    }

    /**
     * @param  list<string>  $kinds
     * @return array{Playlist, list<Track>}
     */
    private function playlist(string $kind, array $kinds): array
    {
        $playlist = Playlist::create(['kind' => $kind]);

        return [$playlist, array_map(function (string $kind) use ($playlist): Track {
            $track = new Track(['kind' => $kind]);
            $track->playlist()->associate($playlist);
            $track->save();

            return $track;
        }, $kinds)];
    }

    private function tracksRevision(Playlist $playlist): string
    {
        return Playlist::query()->findOrFail($playlist->id)->concurrencyRevision(PlaylistRevision::TracksOrder);
    }

    private function recordReorders(): void
    {
        Event::listen(Reordered::class, function (Reordered $event): void {
            $this->reorders[] = [
                $event->change->parent->getKey(),
                array_map(fn (PositionChange $changed): array => [$changed->id, $changed->position, $changed->previousPosition], $event->change->changed),
            ];
        });
    }

    private function addAsset(Section $section, string $title): Asset
    {
        $asset = new Asset(['title' => $title]);
        $asset->section()->associate($section);
        $asset->save();

        return $asset;
    }

    private function key(Model $member): int|string
    {
        $key = $member->getKey();

        $this->assertTrue(is_int($key) || is_string($key));

        return $key;
    }

    /**
     * @param  class-string<Throwable>  $exception
     * @param  Closure(): mixed  $change
     */
    private function assertRejected(Shape $shape, string $exception, Model $parent, Closure $change): void
    {
        $revision = $shape->digest($parent);
        $keys = $shape->rankKeys($parent);
        $thrown = null;

        try {
            $change();
        } catch (Throwable $thrown) {
        }

        $this->assertInstanceOf($exception, $thrown);
        $this->assertSame($revision, $shape->digest($parent));
        $this->assertSame($keys, $shape->rankKeys($parent));
    }

    /**
     * @param  class-string<Model&Ordered>  $class
     * @param  list<mixed>  $values
     * @return array{Model&OrdersRelations, string, list<Model&Ordered>}
     */
    private static function filtered(Model&OrdersRelations $parent, string $relation, string $class, string $foreignKey, string $column, array $values): array
    {
        return [$parent, $relation, array_map(static function (mixed $value) use ($parent, $class, $foreignKey, $column): Model&Ordered {
            $member = $class::query()->create([$foreignKey => $parent->getKey(), $column => $value]);
            assert($member instanceof Ordered);

            return $member;
        }, $values)];
    }

    /**
     * @return array{Model&OrdersRelations&Revisioned, Model&Ordered, Model&Ordered, string}
     */
    private function withStaleDigest(Shape $shape): array
    {
        $parent = $shape->createParent();
        $first = $shape->add($parent);
        $stale = $shape->digest($parent);

        return [$parent, $first, $shape->add($parent), $stale];
    }

    /**
     * @return list<string>
     */
    private function writes(): array
    {
        return array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $statement): bool => preg_match('/^\s*(insert|update|delete)\b/i', $statement) === 1,
        ));
    }
}
