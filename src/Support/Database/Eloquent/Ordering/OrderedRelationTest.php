<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Exceptions\MembershipMismatch;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Agencies\Agency;
use Tests\Fixtures\Support\Agencies\AgencyCustomer;
use Tests\Fixtures\Support\Agencies\AgencyRevision;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Events\ModelEvents;
use Tests\Fixtures\Support\Listings\ListingCustomer;
use Tests\Fixtures\Support\Listings\ListingRevision;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Shapes\BelongsToManyShape;
use Tests\Fixtures\Support\Shapes\HasManyShape;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\TestCase;
use Throwable;

#[CoversClass(MembershipMismatch::class)]
#[CoversClass(OrderChange::class)]
#[CoversClass(OrderedRelation::class)]
#[CoversClass(OrderWrite::class)]
class OrderedRelationTest extends TestCase
{
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
            'a filter after withPositions()' => [fn (OrderedRelation $relation, string $column, array $ids): iterable => $relation->query()->withPositions()->whereIn($column, $ids)->get()],
            'a filter before withPositions()' => [fn (OrderedRelation $relation, string $column, array $ids): iterable => $relation->query()->whereIn($column, $ids)->withPositions()->get()],
            'withPositions() twice' => [fn (OrderedRelation $relation, string $column, array $ids): iterable => $relation->query()->withPositions()->whereIn($column, $ids)->withPositions()->get()],
        ]);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function copiedQueries(): iterable
    {
        return Shape::crossed([
            'the copy alone' => [fn (Relation $query): array => [(clone $query)->withPositions(), $query], [true, false]],
            'the original alone' => [fn (Relation $query): array => [clone $query, $query->withPositions()], [false, true]],
            'both' => [fn (Relation $query): array => [(clone $query)->withPositions(), $query->withPositions()], [true, true]],
            'a copy of a numbered query, numbered again' => [fn (Relation $query): array => [(clone $query->withPositions())->withPositions(), $query], [true, true]],
        ]);
    }

    /**
     * @return iterable<string, array{Closure(Agency, list<string>, Agency): mixed, list<int>, list<int>}>
     */
    public static function untimedPivotWrites(): iterable
    {
        yield 'a reorder' => [
            fn (Agency $agency, array $ids, Agency $other): OrderChange => $agency->orderedRelation(AgencyRevision::CustomersOrder)->reorder(array_reverse($ids), expected: null),
            [2, 1, 0],
            [],
        ];

        yield 'a rebalance' => [
            function (Agency $agency, array $ids, Agency $other): OrderChange {
                $prefix = 'a0'.str_repeat('V', OrderedRelation::MAX_KEY_LENGTH - 3);
                DB::table('agency_customer')->where('id', $ids[0])->update([OrderedRelation::RANK_KEY => $prefix.'V']);
                DB::table('agency_customer')->where('id', $ids[1])->update([OrderedRelation::RANK_KEY => $prefix.'W']);
                $pivot = $agency->orderedRelation(AgencyRevision::CustomersOrder)->query()->get()->all()[2]->getRelation('pivot');
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
    public function it_numbers_every_member_in_one_query_as_the_accessor_and_the_order_change_do(Shape $shape): void
    {
        [$parent, $members] = $shape->with(4);
        $shape->add($parent, hidden: true);
        $change = $members[2]->move(Placement::first(), expected: null);
        DB::enableQueryLog();

        $positions = $shape->selectedPositions($shape->orderedRelation($parent)->query()->withPositions()->get());

        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame(array_combine($change->order, range(1, count($change->order))), $positions);
        $this->assertSame($positions, array_map(fn (int|string $id): mixed => $shape->find($id)->getAttribute('position'), array_combine(array_keys($positions), array_keys($positions))));
    }

    #[DataProvider('positionedQueries')]
    #[Test]
    public function it_numbers_the_members_a_filter_keeps_by_their_place_among_every_member(Shape $shape, Closure $query): void
    {
        [$parent, [, $second, , $fourth]] = $shape->with(4);

        $positions = $shape->selectedPositions($query($shape->orderedRelation($parent), $shape->memberTable().'.id', [$second->getKey(), $fourth->getKey()]));

        $this->assertSame([$second->getKey() => 2, $fourth->getKey() => 4], $positions);
    }

    /**
     * @param  Closure(Relation<Model, Model, mixed>): array{Relation<Model, Model, mixed>, Relation<Model, Model, mixed>}  $copy
     * @param  array{bool, bool}  $numbered
     */
    #[DataProvider('copiedQueries')]
    #[Test]
    public function it_numbers_each_copy_of_a_query_that_withpositions_names(Shape $shape, Closure $copy, array $numbered): void
    {
        [$parent, [$first, $second]] = $shape->with(2);
        $positions = [$first->getKey() => 1, $second->getKey() => 2];
        $missing = [$first->getKey() => 'missing', $second->getKey() => 'missing'];

        $queries = $copy($shape->orderedRelation($parent)->query());

        $this->assertSame([$numbered[0] ? $positions : $missing, $numbered[1] ? $positions : $missing], array_map(fn (Relation $query): array => $this->loadedPositions($shape, $query->get()), $queries));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_keeps_the_position_of_each_member_on_a_later_page(Shape $shape): void
    {
        [$parent, $members] = $shape->with(5);

        $page = $shape->orderedRelation($parent)->query()->withPositions()->paginate(2, page: 2);

        $this->assertSame(5, $page->total());
        $this->assertSame([$members[2]->getKey() => 3, $members[3]->getKey() => 4], $shape->selectedPositions($page->items()));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_numbers_no_trashed_member(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $second->delete();

        $positions = $shape->selectedPositions($shape->orderedRelation($parent)->query()->withPositions()->get());

        $this->assertSame([$first->getKey() => 1, $third->getKey() => 2], $positions);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_numbers_no_pivot_whose_customer_is_trashed(PivotShape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        Customer::query()->findOrFail($second->getAttribute('customer_id'))->delete();

        $positions = $shape->selectedPositions($shape->orderedRelation($parent)->query()->withPositions()->get());

        $this->assertSame([$first->getKey() => 1, $third->getKey() => 2], $positions);
        $this->assertNull($shape->find($this->key($second))->getAttribute('position'));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_gives_no_position_to_a_member_without_a_rank_key(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        DB::table($shape->memberTable())->where('id', $first->getKey())->update([OrderedRelation::RANK_KEY => null]);

        $positions = $shape->selectedPositions($shape->orderedRelation($parent)->query()->withPositions()->get());

        $this->assertSame([$second->getKey() => 1, $third->getKey() => 2, $first->getKey() => null], $positions);
        $this->assertNull($shape->find($this->key($first))->getAttribute('position'));
    }

    #[Test]
    public function it_numbers_no_row_its_relation_excludes(): void
    {
        $section = Section::create();
        $first = $this->addAsset($section, 'Shown');
        $this->addAsset($section, 'Excluded');
        $last = $this->addAsset($section, 'Shown');

        $parent = (new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets', membership: ['assets.title'])] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where('title', 'Shown');
            }
        })->newQuery()->findOrFail($section->id);

        $positions = (new HasManyShape)->selectedPositions($parent->orderedRelation(SectionRevision::AssetsOrder)->query()->withPositions()->get());

        $this->assertSame([$first->id => 1, $last->id => 2], $positions);
    }

    #[Test]
    public function it_numbers_only_the_query_of_an_ordered_relation(): void
    {
        try {
            Section::create()->assets()->withPositions();
            $this->fail('A relation that no ordered relation returned was numbered.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('[withPositions()] numbers only a query that', $exception->getMessage());
        }
    }

    #[Test]
    public function it_numbers_no_pivot_relation_that_does_not_join_its_pivot_table(): void
    {
        [$listing] = (new BelongsToManyShape)->with(1);

        $parent = (new #[OrdersRelation(ListingRevision::CustomersOrder, relation: 'customers')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this, ListingCustomer>
             */
            public function customers(): BelongsToMany
            {
                $relation = $this->belongsToMany(Customer::class, 'listing_customer')->using(ListingCustomer::class)->wherePivotNull('deleted_at');
                $relation->getBaseQuery()->joins = [];

                return $relation;
            }
        })->newQuery()->findOrFail($listing->getKey());

        try {
            $parent->orderedRelation(ListingRevision::CustomersOrder)->query()->withPositions();
            $this->fail('A pivot relation without its pivot join was numbered.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('does not join the pivot table [listing_customer].', $exception->getMessage());
        }
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
    public function it_queries_through_the_parents_relation(Shape $shape): void
    {
        $parent = $shape->createParent();

        $query = $shape->orderedRelation($parent)->query();

        $this->assertSame($shape->relationClass(), $query::class);
        $this->assertSame($parent, $query->getParent());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_drops_every_global_scope_except_soft_deletes(Shape $shape): void
    {
        $parent = $shape->createParent();
        $hidden = $shape->add($parent, hidden: true);
        $trashed = $shape->add($parent);
        $visible = $shape->add($parent);

        $trashed->delete();

        $this->assertSame([$hidden->getKey(), $visible->getKey()], $shape->members($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_reads_the_concurrency_revision_from_the_parent_row(Shape $shape): void
    {
        $parent = $shape->createParent();

        $shape->setStoredRevision($parent, 7);

        $this->assertSame(7, $shape->orderedRelation($parent)->concurrencyRevision());
        $this->assertSame(1, $parent->concurrencyRevision($shape->revision()));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_reorders_every_member(Shape $shape): void
    {
        [$parent, [$first, $second, $third, $fourth]] = $shape->with(4);
        $relation = $shape->orderedRelation($parent);

        $change = $relation->reorder([(string) $this->key($third), (string) $this->key($second), (string) $this->key($first), (string) $this->key($fourth)], expected: 5);

        $this->assertSame([$third->getKey(), $second->getKey(), $first->getKey(), $fourth->getKey()], $shape->members($parent));
        $this->assertSame([$third->getKey(), $second->getKey(), $first->getKey(), $fourth->getKey()], $change->order);
        $this->assertSame([
            ['id' => $this->key($third), 'position' => 1, 'previousPosition' => 3],
            ['id' => $this->key($first), 'position' => 3, 'previousPosition' => 1],
        ], array_map(get_object_vars(...), $change->changed));
        $this->assertSame($relation, $change->relation);
        $this->assertNull($change->source);
        $this->assertSame(6, $change->revision);
        $this->assertSame($shape->storedRevision($parent), $change->revision);
        $this->assertSame($change->revision, $parent->concurrencyRevision($shape->revision()));
        $this->assertFalse($parent->isDirty());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_locks_the_parent_before_it_reorders(Shape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);
        DB::enableQueryLog();

        $shape->orderedRelation($parent)->reorder([$this->key($second), $this->key($first)], expected: 3);

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

        $this->assertRejected($shape, MembershipMismatch::class, $parent, fn (): OrderChange => $shape->orderedRelation($parent)->reorder($ids($keys, $this->key($foreign)), expected: 4));
        $this->assertSame($keys, $shape->members($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_reorder_that_names_a_trashed_member(Shape $shape): void
    {
        [$parent, [$first, $trashed, $third]] = $shape->with(3);
        $trashed->delete();
        $revision = $shape->storedRevision($parent);

        $this->assertRejected($shape, MembershipMismatch::class, $parent, fn (): OrderChange => $shape->orderedRelation($parent)->reorder([$this->key($third), $this->key($trashed), $this->key($first)], expected: $revision));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_stale_reorder_and_changes_nothing(Shape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);

        $this->assertRejected($shape, StaleConcurrencyRevision::class, $parent, fn (): OrderChange => $shape->orderedRelation($parent)->reorder([$this->key($second), $this->key($first)], expected: 2));
        $this->assertSame([$first->getKey(), $second->getKey()], $shape->members($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_stale_reorder_that_keeps_the_order(Shape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);

        $this->assertRejected($shape, StaleConcurrencyRevision::class, $parent, fn (): OrderChange => $shape->orderedRelation($parent)->reorder([$this->key($first), $this->key($second)], expected: 2));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_reorders_without_a_comparison_when_nothing_is_expected(Shape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);
        $shape->setStoredRevision($parent, 40);

        $change = $shape->orderedRelation($parent)->reorder([$this->key($second), $this->key($first)], expected: null);

        $this->assertSame([$second->getKey(), $first->getKey()], $shape->members($parent));
        $this->assertSame(41, $change->revision);
        $this->assertSame($shape->storedRevision($parent), $change->revision);
    }

    /**
     * @param  Closure(list<int|string>): list<int|string>  $reorder
     */
    #[DataProvider('unchangedReorders')]
    #[Test]
    public function it_writes_nothing_for_a_reorder_that_keeps_the_order(Shape $shape, int $count, Closure $reorder, bool $compare): void
    {
        [$parent, $members] = $shape->with($count);
        $relation = $shape->orderedRelation($parent);
        $revision = $shape->storedRevision($parent);
        $keys = $shape->rankKeys($parent);
        $ids = array_map(fn (Model $member): int|string => $this->key($member), $members);
        DB::enableQueryLog();

        $change = $relation->reorder($reorder($ids), $compare ? $revision : null);

        $this->assertSame([], $this->writes());
        $this->assertSame([], $change->changed);
        $this->assertSame($revision, $change->revision);
        $this->assertSame($ids, $change->order);
        $this->assertSame($revision, $shape->storedRevision($parent));
        $this->assertSame($keys, $shape->rankKeys($parent));
    }

    #[Test]
    public function it_orders_by_rank_key_over_an_order_its_relation_declares(): void
    {
        $section = Section::create();
        $zebra = $this->addAsset($section, 'Zebra');
        $alpha = $this->addAsset($section, 'Alpha');

        $parent = (new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->orderBy('title');
            }
        })->newQuery()->findOrFail($section->id);
        $relation = $parent->orderedRelation(SectionRevision::AssetsOrder);

        $this->assertSame([$zebra->id, $alpha->id], $relation->query()->pluck('assets.id')->all());

        $unchanged = $relation->reorder([$zebra->id, $alpha->id], expected: null);

        $this->assertSame([], $unchanged->changed);
        $this->assertSame([$zebra->id, $alpha->id], $unchanged->order);

        $change = $relation->reorder([$alpha->id, $zebra->id], expected: null);

        $this->assertSame([$alpha->id, $zebra->id], $change->order);
        $this->assertSame([
            ['id' => $alpha->id, 'position' => 1, 'previousPosition' => 2],
            ['id' => $zebra->id, 'position' => 2, 'previousPosition' => 1],
        ], array_map(get_object_vars(...), $change->changed));
        $this->assertSame([$alpha->id, $zebra->id], $relation->query()->pluck('assets.id')->all());
    }

    #[Test]
    public function it_keeps_a_row_its_relation_excludes_after_the_member_that_precedes_it(): void
    {
        $section = Section::create();
        $first = $this->addAsset($section, 'Shown');
        $excluded = $this->addAsset($section, 'Excluded');
        $last = $this->addAsset($section, 'Shown');

        $parent = (new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets', membership: ['assets.title'])] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where('title', 'Shown');
            }
        })->newQuery()->findOrFail($section->id);

        $change = $parent->orderedRelation(SectionRevision::AssetsOrder)->reorder([$last->id, $first->id], expected: null);

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

        $parent = (new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->withTrashed();
            }
        })->newQuery()->findOrFail($section->id);

        $change = $parent->orderedRelation(SectionRevision::AssetsOrder)->reorder([$trashed->id, $first->id], expected: null);

        $this->assertSame([$trashed->id, $first->id], $change->order);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_reorders_by_upper_case_uuids(PivotShape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);

        $change = $shape->orderedRelation($parent)->reorder([strtoupper((string) $this->key($second)), strtoupper((string) $this->key($first))], expected: 3);

        $this->assertSame([$second->getKey(), $first->getKey()], $shape->members($parent));
        $this->assertSame([$second->getKey(), $first->getKey()], $change->order);
    }

    #[Test]
    public function it_reorders_a_uuid_pivot_that_declares_an_integer_key_type(): void
    {
        $shape = new BelongsToManyShape;
        [$listing, [$first, $second]] = $shape->with(2);
        $parent = $this->parentOfPivot($listing, $this->integerKeyedPivot());

        $change = $parent->orderedRelation(ListingRevision::CustomersOrder)->reorder([(string) $this->key($second), (string) $this->key($first)], expected: null);

        $this->assertSame([$second->getKey(), $first->getKey()], $shape->members($listing));
        $this->assertSame([$second->getKey(), $first->getKey()], $change->order);
    }

    #[Test]
    public function it_reorders_by_upper_case_uuids_on_a_pivot_that_does_not_use_uuids(): void
    {
        $shape = new BelongsToManyShape;
        [$listing, [$first, $second]] = $shape->with(2);
        $parent = $this->parentOfPivot($listing, $this->stringKeyedPivot());

        $change = $parent->orderedRelation(ListingRevision::CustomersOrder)->reorder([strtoupper((string) $this->key($second)), strtoupper((string) $this->key($first))], expected: null);

        $this->assertSame([$second->getKey(), $first->getKey()], $shape->members($listing));
        $this->assertSame([$second->getKey(), $first->getKey()], $change->order);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_reorders_pivots_on_the_connection_of_their_parent(PivotShape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);
        $default = config()->string('database.default');
        config()->set('database.default', 'sqlite');

        try {
            $change = $shape->orderedRelation($parent)->reorder([$this->key($second), $this->key($first)], expected: null);
        } finally {
            config()->set('database.default', $default);
        }

        $this->assertSame([$second->getKey(), $first->getKey()], $shape->members($parent));
        $this->assertSame($shape->storedRevision($parent), $change->revision);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_fires_the_model_events_of_each_member_a_reorder_rewrites_and_then_of_the_parent(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $this->travel(1)->minutes();
        $rows = array_map(ModelEvents::row(...), [$first, $second, $third]);
        $revision = $shape->storedRevision($parent);
        $events = ModelEvents::record();

        $shape->orderedRelation($parent)->reorder([$this->key($third), $this->key($second), $this->key($first)], expected: null);

        $this->assertSame($rows[1], ModelEvents::row($second));
        $this->assertSame([
            ...self::savedEvents($third, $rows[2], ModelEvents::row($third)),
            ...self::savedEvents($first, $rows[0], ModelEvents::row($first)),
            ...self::raisedEvents($parent, $shape->column(), $revision),
        ], self::sqlOf($events->fired));
        $this->assertSame($revision + 1, $shape->storedRevision($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_fires_the_model_events_of_each_member_a_rebalance_rewrites_and_of_the_moved_member_again(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $shape->crowd($first, $second);
        DB::table($shape->memberTable())->where('id', $third->getKey())->update([OrderedRelation::RANK_KEY => 'a5']);
        $this->travel(1)->minutes();
        $rows = array_map(ModelEvents::row(...), [$first, $second, $third]);
        $rebalanced = [...$rows[2], OrderedRelation::RANK_KEY => FractionalIndex::keysBetween(null, null, 3)[2], 'updated_at' => now()->toDateTimeString()];
        $revision = $shape->storedRevision($parent);
        $events = ModelEvents::record();

        $third->move(Placement::after($this->key($first)), expected: null);

        $this->assertLessThan(OrderedRelation::MAX_KEY_LENGTH, $shape->longestRankKey($parent));
        $this->assertSame([
            ...self::savedEvents($first, $rows[0], ModelEvents::row($first)),
            ...self::savedEvents($second, $rows[1], ModelEvents::row($second)),
            ...self::savedEvents($third, $rows[2], $rebalanced),
            ...self::savedEvents($third, $rebalanced, ModelEvents::row($third)),
            ...self::raisedEvents($parent, $shape->column(), $revision),
        ], self::sqlOf($events->fired));
        $this->assertSame($revision + 1, $shape->storedRevision($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_advances_the_updated_at_of_each_member_a_reorder_rewrites_and_not_of_the_parent(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $stamps = fn (): array => array_map(fn (Model $model): mixed => ModelEvents::row($model)['updated_at'], [$first, $second, $third, $parent]);
        $before = $stamps();
        $this->travel(1)->minutes();

        $shape->orderedRelation($parent)->reorder([$this->key($third), $this->key($second), $this->key($first)], expected: null);

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

    /**
     * @param  Closure(): (Model&OrdersRelations&Revisioned)  $parent
     */
    #[DataProvider('uninspectableMemberships')]
    #[Test]
    public function it_refuses_a_relation_whose_membership_filter_it_cannot_verify(Closure $parent, NamesConcurrencyRevision $revision, string $message): void
    {
        try {
            $parent()->orderedRelation($revision);
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
        $this->assertSame($revision, $parent()->orderedRelation($revision)->revision);
    }

    /**
     * @return iterable<string, array{Closure(): (Model&OrdersRelations&Revisioned), NamesConcurrencyRevision, string}>
     */
    public static function uninspectableMemberships(): iterable
    {
        yield 'a raw clause' => [static fn (): Model&OrdersRelations&Revisioned => new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->whereRaw('"assets"."title" = ?', ['Shown']);
            }
        }, SectionRevision::AssetsOrder, '::assets()] filters its members through a clause of type [raw]'];

        yield 'an exists clause' => [static fn (): Model&OrdersRelations&Revisioned => new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->whereExists(static function (Builder $query): void {
                    $query->from('videos')->whereColumn('videos.section_id', 'assets.section_id');
                });
            }
        }, SectionRevision::AssetsOrder, '::assets()] filters its members through a clause of type [Exists]'];

        yield 'an expression column' => [static fn (): Model&OrdersRelations&Revisioned => new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where(new Expression('lower("assets"."title")'), 'shown');
            }
        }, SectionRevision::AssetsOrder, '::assets()] filters its members by an expression'];

        yield 'a top-level or' => [static fn (): Model&OrdersRelations&Revisioned => new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->orWhere('assets.title', 'Shown');
            }
        }, SectionRevision::AssetsOrder, '::assets()] joins a filter to its members with [or]'];

        yield 'an undeclared item column' => [static fn (): Model&OrdersRelations&Revisioned => new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where('title', 'Shown');
            }
        }, SectionRevision::AssetsOrder, '::assets()] filters its members by [assets.title], which its membership does not declare.'];

        yield 'an undeclared parent column' => [static fn (): Model&OrdersRelations&Revisioned => new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets', membership: ['assets.title'])] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where('assets.title', $this->getAttribute('name'));
            }
        }, SectionRevision::AssetsOrder, '::assets()] filters its members by [sections.name], which its membership does not declare.'];

        yield 'an unqualified pivot column' => [static fn (): Model&OrdersRelations&Revisioned => new #[OrdersRelation(ListingRevision::CustomersOrder, relation: 'customers')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this, ListingCustomer>
             */
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id')->using(ListingCustomer::class)->where('title', 'Shown');
            }
        }, ListingRevision::CustomersOrder, '::customers()] filters its members by the unqualified column [title]'];

        yield 'an unqualified declaration' => [static fn (): Model&OrdersRelations&Revisioned => new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets', membership: ['title'])] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where('assets.title', 'Shown');
            }
        }, SectionRevision::AssetsOrder, '::assets()] declares the membership column [title], which names no table of the relation.'];

        yield 'a declaration on another table' => [static fn (): Model&OrdersRelations&Revisioned => new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets', membership: ['videos.title'])] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id');
            }
        }, SectionRevision::AssetsOrder, '::assets()] declares the membership column [videos.title], which names no table of the relation.'];
    }

    /**
     * @return iterable<string, array{Closure(): (Model&OrdersRelations&Revisioned), NamesConcurrencyRevision}>
     */
    public static function inspectableMemberships(): iterable
    {
        yield 'basic, null, in and column clauses on declared item columns' => [static fn (): Model&OrdersRelations&Revisioned => new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets', membership: ['assets.title', 'assets.visible', 'assets.created_at', 'assets.updated_at'])] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')
                    ->where('title', 'Shown')
                    ->whereNotNull('assets.title')
                    ->whereIn('assets.visible', [true])
                    ->whereColumn('assets.created_at', '<=', 'assets.updated_at');
            }
        }, SectionRevision::AssetsOrder];

        yield 'an or inside a nested clause' => [static fn (): Model&OrdersRelations&Revisioned => new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets', membership: ['assets.title'])] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where(static function (EloquentBuilder $query): void {
                    $query->where('assets.title', 'Shown')->orWhereNull('assets.title');
                });
            }
        }, SectionRevision::AssetsOrder];

        yield 'a declared parent column' => [static fn (): Model&OrdersRelations&Revisioned => new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets', membership: ['assets.title', 'sections.name'])] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id')->where('assets.title', $this->getAttribute('name'));
            }
        }, SectionRevision::AssetsOrder];

        yield 'a declared pivot column and a declared related column' => [static fn (): Model&OrdersRelations&Revisioned => new #[OrdersRelation(ListingRevision::CustomersOrder, relation: 'customers', membership: ['listing_customer.title', 'customers.name'])] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this, ListingCustomer>
             */
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id')
                    ->using(ListingCustomer::class)
                    ->wherePivotNull('deleted_at')
                    ->wherePivot('title', 'Shown')
                    ->where('customers.name', 'Ada');
            }
        }, ListingRevision::CustomersOrder];
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

    /**
     * @param  class-string<Pivot&Ordered>  $pivot
     */
    private function parentOfPivot(Model $listing, string $pivot): Model&OrdersRelations&Revisioned
    {
        $declared = new #[OrdersRelation(ListingRevision::CustomersOrder, relation: 'customers')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            /** @var class-string<Pivot> */
            public static string $pivot;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this>
             */
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id', 'customer_id')->using(self::$pivot)->wherePivotNull('deleted_at');
            }
        };
        $declared::$pivot = $pivot;

        $parent = $declared->newQuery()->findOrFail($listing->getKey());
        assert($parent instanceof OrdersRelations && $parent instanceof Revisioned);

        return $parent;
    }

    /**
     * @param  iterable<Model>  $results
     * @return array<int|string, mixed>
     */
    private function loadedPositions(Shape $shape, iterable $results): array
    {
        $positions = [];

        foreach ($results as $result) {
            $item = $shape->item($result);
            $attributes = $item->getAttributes();
            $positions[$this->key($item)] = array_key_exists(OrderedRelation::POSITION, $attributes) ? $attributes[OrderedRelation::POSITION] : 'missing';
        }

        return $positions;
    }

    /**
     * @return list<string>
     */
    private function agencyMembers(Agency $agency): array
    {
        return $agency->orderedRelation(AgencyRevision::CustomersOrder)->query()->get()->map(function (Model $customer): string {
            $key = $customer->getRelation('pivot')?->getKey();
            assert(is_string($key));

            return $key;
        })->values()->all();
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
        $revision = $shape->storedRevision($parent);
        $keys = $shape->rankKeys($parent);
        $thrown = null;

        try {
            $change();
        } catch (Throwable $thrown) {
        }

        $this->assertInstanceOf($exception, $thrown);
        $this->assertSame($revision, $shape->storedRevision($parent));
        $this->assertSame($keys, $shape->rankKeys($parent));
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
