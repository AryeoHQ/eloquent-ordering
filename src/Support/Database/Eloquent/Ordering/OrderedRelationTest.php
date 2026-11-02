<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
use Support\Database\Eloquent\Ordering\Exceptions\MembershipMismatch;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Agencies\Agency;
use Tests\Fixtures\Support\Agencies\AgencyCustomer;
use Tests\Fixtures\Support\Agencies\AgencyRevision;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Documents\DocumentKind;
use Tests\Fixtures\Support\Documents\KindedDocument;
use Tests\Fixtures\Support\Events\ModelEvents;
use Tests\Fixtures\Support\Folders\FolderRevision;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingCustomer;
use Tests\Fixtures\Support\Listings\ListingRevision;
use Tests\Fixtures\Support\Listings\NamedListing;
use Tests\Fixtures\Support\Playlists\Playlist;
use Tests\Fixtures\Support\Playlists\PlaylistRevision;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Shapes\BelongsToManyShape;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\Fixtures\Support\Tracks\Track;
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
            fn (Agency $agency, array $ids, Agency $other): OrderChange => $agency->customers()->arrange(array_reverse($ids)),
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
        $change = $shape->relation($parent)->arrange([$this->key($second), $this->key($first)]);

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
        $change = $shape->arrange($parent, [$this->key($visible), $this->key($hidden)]);

        $this->assertSame([$visible->getKey(), $hidden->getKey()], $change->order);
        $this->assertSame([$visible->getKey(), $hidden->getKey()], $shape->members($parent));
        $this->assertRejected($shape, MembershipMismatch::class, $parent, fn (): OrderChange => $shape->arrange($parent, [$this->key($visible)]));
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

        $unchanged = Shape::relationOf($parent, SectionRevision::AssetsOrder)->arrange([$zebra->id, $alpha->id]);

        $this->assertSame([], $unchanged->changed);
        $this->assertSame([$zebra->id, $alpha->id], $unchanged->order);

        $change = Shape::relationOf($parent, SectionRevision::AssetsOrder)->arrange([$alpha->id, $zebra->id]);

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
        $parent = $this->parentOfTwoPivotRelations($listing);
        [$listed, $represented] = [$shape->members($listing), $this->agencyMembers($agency)];

        $listings = Shape::relationOf($parent, ListingRevision::CustomersOrder)->arrange(array_reverse($listed));

        $this->assertSame(ListingRevision::CustomersOrder, $listings->revision);
        $this->assertSame(array_reverse($listed), $listings->order);
        $this->assertSame(array_reverse($listed), $shape->members($listing));
        $this->assertSame($represented, $this->agencyMembers($agency));

        $agencies = Shape::relationOf($parent, ListingRevision::ScenesOrder)->arrange([$represented[1], $represented[2], $represented[0]]);

        $this->assertSame(ListingRevision::ScenesOrder, $agencies->revision);
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
     * @return iterable<string, array{Closure(): (Model&OrdersRelations&Revisioned), NamesConcurrencyRevision}>
     */
    public static function inspectableMemberships(): iterable
    {
        yield 'basic, null, in and column clauses on declared item columns' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
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
                    ->whereIn('assets.visible', [true])
                    ->whereColumn('assets.visible', '=', 'assets.visible');
            }
        }, SectionRevision::AssetsOrder];

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

        yield 'a declared pivot column' => [static fn (): Model&OrdersRelations&Revisioned => new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Model, $this, ListingCustomer>
             */
            #[OrdersRelation(ListingRevision::CustomersOrder, membership: ['listing_customer.title'])]
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(OrderedRelationTest::unwatchedCustomer(), 'listing_customer', 'listing_id', 'customer_id')
                    ->using(ListingCustomer::class)
                    ->wherePivotNull('deleted_at')
                    ->wherePivot('title', 'Shown');
            }
        }, ListingRevision::CustomersOrder];

        yield 'a declared related column' => [static fn (): Model&OrdersRelations&Revisioned => new NamedListing, ListingRevision::CustomersOrder];
    }

    /**
     * @return class-string<Model>
     */
    public static function unwatchedCustomer(): string
    {
        return (new class extends Model
        {
            protected $table = 'customers';
        })::class;
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
    private function parentOfPivot(Model $listing, string $pivot): Model&OrdersRelations&Revisioned
    {
        $declared = new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            /** @var class-string<Pivot> */
            public static string $pivot;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Model, $this>
             */
            #[OrdersRelation(ListingRevision::CustomersOrder)]
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(OrderedRelationTest::unwatchedCustomer(), 'listing_customer', 'listing_id', 'customer_id')->using(self::$pivot)->wherePivotNull('deleted_at');
            }
        };
        $declared::$pivot = $pivot;

        $parent = $declared->newQuery()->findOrFail($listing->getKey());
        assert($parent instanceof OrdersRelations && $parent instanceof Revisioned);

        return $parent;
    }

    private function parentOfTwoPivotRelations(Listing $listing): Model&OrdersRelations&Revisioned
    {
        $parent = (new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Model, $this, ListingCustomer>
             */
            #[OrdersRelation(ListingRevision::CustomersOrder)]
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(OrderedRelationTest::unwatchedCustomer(), 'listing_customer', 'listing_id', 'customer_id')->using(ListingCustomer::class)->wherePivotNull('deleted_at');
            }

            /**
             * @return BelongsToMany<Model, $this, AgencyCustomer>
             */
            #[OrdersRelation(ListingRevision::ScenesOrder)]
            public function agencyCustomers(): BelongsToMany
            {
                return $this->belongsToMany(OrderedRelationTest::unwatchedCustomer(), 'agency_customer', 'agency_id', 'customer_id')->using(AgencyCustomer::class)->wherePivotNull('deleted_at');
            }
        })->newQuery()->findOrFail($listing->id);
        assert($parent instanceof OrdersRelations && $parent instanceof Revisioned);

        return $parent;
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
