<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Exceptions\MembershipMismatch;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\TestCase;
use Throwable;

#[CoversClass(MembershipMismatch::class)]
#[CoversClass(OrderChange::class)]
#[CoversClass(OrderedRelation::class)]
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
        $this->assertSame([
            ['id' => $third->getKey(), 'position' => 1],
            ['id' => $second->getKey(), 'position' => 2],
            ['id' => $first->getKey(), 'position' => 3],
            ['id' => $fourth->getKey(), 'position' => 4],
        ], $change->order);
        $this->assertSame([
            ['id' => $third->getKey(), 'position' => 1, 'previous_position' => 3],
            ['id' => $first->getKey(), 'position' => 3, 'previous_position' => 1],
        ], $change->changed);
        $this->assertSame($relation, $change->relation);
        $this->assertNull($change->source);
        $this->assertGreaterThan(5, $change->revision);
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
        $this->assertGreaterThan(40, $change->revision);
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
        $this->assertSame(array_map(fn (int|string $id, int $index): array => ['id' => $id, 'position' => $index + 1], $ids, array_keys($ids)), $change->order);
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
        $this->assertSame([['id' => $zebra->id, 'position' => 1], ['id' => $alpha->id, 'position' => 2]], $unchanged->order);

        $change = $relation->reorder([$alpha->id, $zebra->id], expected: null);

        $this->assertSame([['id' => $alpha->id, 'position' => 1], ['id' => $zebra->id, 'position' => 2]], $change->order);
        $this->assertSame([
            ['id' => $alpha->id, 'position' => 1, 'previous_position' => 2],
            ['id' => $zebra->id, 'position' => 2, 'previous_position' => 1],
        ], $change->changed);
        $this->assertSame([$alpha->id, $zebra->id], $relation->query()->pluck('assets.id')->all());
    }

    #[Test]
    public function it_keeps_a_row_its_relation_excludes_after_the_member_that_precedes_it(): void
    {
        $section = Section::create();
        $first = $this->addAsset($section, 'Shown');
        $excluded = $this->addAsset($section, 'Excluded');
        $last = $this->addAsset($section, 'Shown');

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
                return $this->hasMany(Asset::class, 'section_id')->where('title', 'Shown');
            }
        })->newQuery()->findOrFail($section->id);

        $change = $parent->orderedRelation(SectionRevision::AssetsOrder)->reorder([$last->id, $first->id], expected: null);

        $this->assertSame([['id' => $last->id, 'position' => 1], ['id' => $first->id, 'position' => 2]], $change->order);
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

        $this->assertSame([['id' => $trashed->id, 'position' => 1], ['id' => $first->id, 'position' => 2]], $change->order);
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
