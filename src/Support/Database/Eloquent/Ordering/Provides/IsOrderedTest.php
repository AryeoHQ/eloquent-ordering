<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Exceptions\InvalidAnchor;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Support\Database\Eloquent\Ordering\OrderChange;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Placement;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingRevision;
use Tests\Fixtures\Support\Scenes\Scene;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Shapes\HasManyShape;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Tours\TourRevision;
use Tests\Fixtures\Support\Videos\Video;
use Tests\TestCase;
use Throwable;

#[CoversClass(InvalidAnchor::class)]
#[CoversClass(Placement::class)]
#[CoversTrait(IsOrdered::class)]
class IsOrderedTest extends TestCase
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
    public static function moves(): iterable
    {
        return Shape::crossed([
            'to the front' => [3, 'first', null, [3, 0, 1, 2]],
            'to the back' => [0, 'last', null, [1, 2, 3, 0]],
            'before an anchor' => [3, 'before', 1, [0, 3, 1, 2]],
            'after an anchor' => [0, 'after', 2, [1, 2, 0, 3]],
        ]);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function unchangedMoves(): iterable
    {
        return Shape::crossed([
            'a move to where it is' => [3, fn (array $members, null|int $expected): OrderChange => $members[1]->move(Placement::after($members[0]->getKey()), $expected)],
            'a move before its successor' => [3, fn (array $members, null|int $expected): OrderChange => $members[1]->move(Placement::before($members[2]->getKey()), $expected)],
            'a move of the first member to the front' => [3, fn (array $members, null|int $expected): OrderChange => $members[0]->move(Placement::first(), $expected)],
            'a move of the last member to the back' => [3, fn (array $members, null|int $expected): OrderChange => $members[2]->move(Placement::last(), $expected)],
            'a move of the only member' => [1, fn (array $members, null|int $expected): OrderChange => $members[0]->move(Placement::first(), $expected)],
            'a move into the parent it is in' => [2, fn (array $members, null|int $expected): OrderChange => $members[1]->move(Placement::last()->in($members[1]->orderedRelation()->parent), $expected)],
            'a move that expects nothing' => [3, fn (array $members, null|int $expected): OrderChange => $members[1]->move(Placement::after($members[0]->getKey()), null)],
        ]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function lockedSections(): iterable
    {
        yield 'while the first parent is locked' => [0];
        yield 'while the second parent is locked' => [1];
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_places_a_new_member_last_by_default(Shape $shape): void
    {
        $parent = $shape->createParent();

        $first = $shape->add($parent);
        $second = $shape->add($parent);
        $third = $shape->add($parent, Placement::last());

        $this->assertOrder($shape, [$first->getKey(), $second->getKey(), $third->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_places_a_new_member_first(Shape $shape): void
    {
        $parent = $shape->createParent();

        $second = $shape->add($parent);
        $first = $shape->add($parent, Placement::first());

        $this->assertOrder($shape, [$first->getKey(), $second->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_places_a_new_member_before_an_anchor(Shape $shape): void
    {
        $parent = $shape->createParent();

        $first = $shape->add($parent);
        $third = $shape->add($parent);
        $second = $shape->add($parent, Placement::before($this->key($third)));

        $this->assertOrder($shape, [$first->getKey(), $second->getKey(), $third->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_places_a_new_member_after_an_anchor(Shape $shape): void
    {
        $parent = $shape->createParent();

        $first = $shape->add($parent);
        $third = $shape->add($parent);
        $second = $shape->add($parent, Placement::after($this->key($first)));

        $this->assertOrder($shape, [$first->getKey(), $second->getKey(), $third->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_an_anchor_outside_the_relation(Shape $shape): void
    {
        $parent = $shape->createParent();
        $member = $shape->add($parent);
        $foreign = $shape->add($shape->createParent());

        try {
            $shape->add($parent, Placement::before($this->key($foreign)));
            $this->fail('A foreign anchor placed a new member.');
        } catch (InvalidAnchor) {
            $this->assertOrder($shape, [$member->getKey()], $parent);
            $this->assertSame(2, $shape->storedRevision($parent));
        }
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_trashed_anchor(Shape $shape): void
    {
        $parent = $shape->createParent();
        $trashed = $shape->add($parent);
        $trashed->delete();

        $this->expectException(InvalidAnchor::class);

        $shape->add($parent, Placement::after($this->key($trashed)));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_an_anchor_that_cannot_be_a_key(Shape $shape): void
    {
        $parent = $shape->createParent();
        $shape->add($parent);

        $this->expectException(InvalidAnchor::class);

        $shape->add($parent, Placement::after('first'));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_numbers_every_member_from_one_whatever_the_caller_can_see(Shape $shape): void
    {
        $parent = $shape->createParent();

        $hidden = $shape->add($parent, hidden: true);
        $visible = $shape->add($parent);

        $this->assertSame(2, $shape->memberClass()::query()->findOrFail($visible->getKey())->getAttribute('position'));
        $this->assertOrder($shape, [$hidden->getKey(), $visible->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_keeps_positions_dense_when_a_member_is_deleted(Shape $shape): void
    {
        $parent = $shape->createParent();

        $first = $shape->add($parent);
        $second = $shape->add($parent);
        $third = $shape->add($parent);

        $second->delete();

        $this->assertNull($second->getAttribute('position'));
        $this->assertOrder($shape, [$first->getKey(), $third->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_takes_a_soft_deleted_member_out_of_the_relation(Shape $shape): void
    {
        $parent = $shape->createParent();
        $member = $shape->add($parent);
        $other = $shape->add($parent);

        $member->delete();

        $this->assertNotNull($member->getAttribute('deleted_at'));
        $this->assertNull($member->getAttribute('rank_key'));
        $this->assertNull(DB::table($shape->memberTable())->where('id', $member->getKey())->value('rank_key'));
        $this->assertSame([$other->getKey()], $shape->members($parent));
        $this->assertOrder($shape, [$other->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_increments_the_order_revision_for_a_create_and_a_delete_only(Shape $shape): void
    {
        $parent = $shape->createParent();

        $member = $shape->add($parent);
        $this->assertSame(2, $shape->storedRevision($parent));

        $member->update(['title' => 'Dusk']);
        $this->assertSame(2, $shape->storedRevision($parent));

        $member->forceDelete();
        $this->assertSame(3, $shape->storedRevision($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_writes_the_order_revision_into_the_parent_it_holds_after_a_create(Shape $shape): void
    {
        $parent = $shape->createParent();

        $member = $shape->add($parent);

        $this->assertSame(2, $parent->concurrencyRevision($shape->revision()));
        $this->assertFalse($parent->isDirty());
        $this->assertSame($parent, $member->orderedRelation()->parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_writes_the_order_revision_into_the_parent_it_holds_after_a_soft_delete(Shape $shape): void
    {
        $parent = $shape->createParent();
        $member = $shape->add($parent);

        $member->delete();

        $this->assertGreaterThan(2, $parent->concurrencyRevision($shape->revision()));
        $this->assertSame($shape->storedRevision($parent), $parent->concurrencyRevision($shape->revision()));
        $this->assertFalse($parent->isDirty());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_writes_the_order_revision_into_the_parent_it_holds_after_a_force_delete(Shape $shape): void
    {
        $parent = $shape->createParent();
        $member = $shape->add($parent);

        $member->forceDelete();

        $this->assertSame(3, $parent->concurrencyRevision($shape->revision()));
        $this->assertFalse($parent->isDirty());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_writes_the_order_revision_into_the_parent_it_resolves(Shape $shape): void
    {
        $parent = $shape->createParent();

        $member = $shape->newUnheldMember($parent);
        $member->save();

        $this->assertNotSame($parent, $member->orderedRelation()->parent);
        $this->assertSame(2, $member->orderedRelation()->parent->concurrencyRevision($shape->revision()));
    }

    #[Test]
    public function it_resolves_the_relation_that_orders_its_class_when_the_parent_orders_two_classes(): void
    {
        $shape = new HasManyShape;
        $section = Section::create();

        $asset = $shape->add($section);
        $this->assertSame(2, $shape->storedRevision($section, SectionRevision::AssetsOrder));
        $this->assertSame(1, $shape->storedRevision($section, SectionRevision::VideosOrder));

        $video = new Video;
        $video->section()->associate($section);
        $video->save();
        $this->assertSame(2, $shape->storedRevision($section, SectionRevision::AssetsOrder));
        $this->assertSame(2, $shape->storedRevision($section, SectionRevision::VideosOrder));

        $this->assertSame(SectionRevision::AssetsOrder, $asset->orderedRelation()->revision);
        $this->assertSame(SectionRevision::VideosOrder, $video->orderedRelation()->revision);
        $this->assertSame(1, $asset->getAttribute('position'));
        $this->assertSame(1, $video->position);
        $this->assertSame([$video->id], $section->orderedRelation(SectionRevision::VideosOrder)->query()->pluck('id')->all());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_places_a_new_member_in_the_relation_that_its_foreign_key_names(Shape $shape): void
    {
        $cached = $shape->createParent();
        $named = $shape->createParent();
        $member = $shape->newUnheldMember($named)->setRelation($shape->parentRelation(), $cached);

        $member->save();

        $this->assertSame(1, $shape->storedRevision($cached));
        $this->assertSame(2, $shape->storedRevision($named));
        $this->assertOrder($shape, [$member->getKey()], $named);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_locks_the_parent_before_it_inserts(Shape $shape): void
    {
        $parent = $shape->createParent();
        DB::enableQueryLog();

        $shape->add($parent);

        $this->assertParentLockedBefore($shape, sprintf('insert into "%s"', $shape->memberTable()));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_locks_the_parent_before_it_soft_deletes(Shape $shape): void
    {
        $member = $shape->add($shape->createParent());
        DB::enableQueryLog();

        $member->delete();

        $this->assertParentLockedBefore($shape, sprintf('update "%s"', $shape->memberTable()));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_locks_the_parent_before_it_force_deletes(Shape $shape): void
    {
        $member = $shape->add($shape->createParent());
        DB::enableQueryLog();

        $member->forceDelete();

        $this->assertParentLockedBefore($shape, sprintf('delete from "%s"', $shape->memberTable()));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_refuses_a_new_member_without_a_parent(Shape $shape): void
    {
        $class = $shape->memberClass();

        $this->expectException(LogicException::class);

        (new $class)->save();
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_writes_nothing_when_a_later_listener_halts_a_create(Shape $shape): void
    {
        $parent = $shape->createParent();
        $member = $shape->add($parent);

        $shape->memberClass()::creating(fn (): bool => false);

        $halted = $shape->newMember($parent);

        $this->assertFalse($halted->save());
        $this->assertFalse($halted->exists);
        $this->assertSame(2, $shape->storedRevision($parent));
        $this->assertOrder($shape, [$member->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_writes_nothing_when_a_later_listener_halts_a_delete(Shape $shape): void
    {
        $parent = $shape->createParent();
        $member = $shape->add($parent);

        $shape->memberClass()::deleting(fn (): bool => false);

        $this->assertContains($member->delete(), [false, 0]);
        $this->assertSame(2, $shape->storedRevision($parent));
        $this->assertOrder($shape, [$member->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_keeps_the_rank_key_out_of_its_serialized_form(Shape $shape): void
    {
        $member = $shape->add($shape->createParent());

        $this->assertArrayNotHasKey('rank_key', $member->toArray());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_has_no_position_until_it_is_saved(Shape $shape): void
    {
        $class = $shape->memberClass();

        $this->assertNull((new $class)->getAttribute('position'));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_refuses_a_placement_for_an_existing_member(Shape $shape): void
    {
        $member = $shape->add($shape->createParent());

        $this->expectException(LogicException::class);

        $member->placeAt(Placement::first());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_refuses_a_placement_that_names_a_parent_for_a_new_member(Shape $shape): void
    {
        $parent = $shape->createParent();
        $class = $shape->memberClass();

        $this->expectException(LogicException::class);

        (new $class)->placeAt(Placement::first()->in($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rebalances_the_keys_rather_than_place_a_new_member_past_the_longest_key(Shape $shape): void
    {
        $parent = $shape->createParent();
        $first = $shape->add($parent);
        $next = $shape->add($parent);
        $inserted = [$next->getKey()];

        while (strlen(FractionalIndex::keyBetween($this->rankKey($first), $this->rankKey($next))) <= OrderedRelation::MAX_KEY_LENGTH) {
            $next = $shape->add($parent, Placement::after($this->key($first)));
            array_unshift($inserted, $next->getKey());
        }

        $longest = $shape->longestRankKey($parent);

        $member = $shape->add($parent, Placement::after($this->key($first)));

        $this->assertLessThan($longest, $shape->longestRankKey($parent));
        $this->assertOrder($shape, [$first->getKey(), $member->getKey(), ...$inserted], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_locks_the_parent_that_a_stale_instance_now_belongs_to_before_it_deletes(Shape $shape): void
    {
        $source = $shape->createParent();
        $destination = $shape->createParent();
        $member = $shape->add($source);
        $stale = $shape->find($this->key($member));

        $shape->associate($member, $destination);
        $member->save();
        $revision = $shape->storedRevision($source);
        DB::enableQueryLog();

        $stale->delete();

        $this->assertParentLockedBefore($shape, sprintf('update "%s"', $shape->memberTable()), $destination);
        $this->assertSame($revision, $shape->storedRevision($source));
        $this->assertSoftDeleted($member);
    }

    /**
     * @param  list<int>  $expected
     */
    #[DataProvider('moves')]
    #[Test]
    public function it_moves_a_member_within_its_parent(Shape $shape, int $moved, string $where, null|int $anchor, array $expected): void
    {
        [$parent, $members] = $shape->with(4);

        $placement = match ($where) {
            'first' => Placement::first(),
            'last' => Placement::last(),
            'before' => Placement::before($this->key($members[(int) $anchor])),
            default => Placement::after($this->key($members[(int) $anchor])),
        };

        $change = $members[$moved]->move($placement, expected: 5);

        $ids = array_map(fn (int $index): mixed => $members[$index]->getKey(), $expected);

        $this->assertOrder($shape, $ids, $parent);
        $this->assertSame(array_map(fn (mixed $id, int $index): array => ['id' => $id, 'position' => $index + 1], $ids, array_keys($ids)), $change->order);
        $this->assertSame([['id' => $members[$moved]->getKey(), 'position' => array_search($moved, $expected, true) + 1, 'previous_position' => $moved + 1]], $change->changed);
        $this->assertSame($parent, $change->relation->parent);
        $this->assertSame($shape->revision(), $change->relation->revision);
        $this->assertNull($change->source);
        $this->assertGreaterThan(5, $change->revision);
        $this->assertSame($shape->storedRevision($parent), $change->revision);
        $this->assertSame($change->revision, $parent->concurrencyRevision($shape->revision()));
        $this->assertFalse($parent->isDirty());
        $this->assertFalse($members[$moved]->isDirty());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_moves_a_member_between_parents(Shape $shape): void
    {
        [$source, [$first, $moved, $third]] = $shape->with(3);
        [$destination, [$anchor, $last]] = $shape->with(2);

        $change = $moved->move(Placement::after($this->key($anchor))->in($destination), expected: 4);

        $this->assertOrder($shape, [$first->getKey(), $third->getKey()], $source);
        $this->assertOrder($shape, [$anchor->getKey(), $moved->getKey(), $last->getKey()], $destination);

        $this->assertSame($destination, $change->relation->parent);
        $this->assertSame([['id' => $anchor->getKey(), 'position' => 1], ['id' => $moved->getKey(), 'position' => 2], ['id' => $last->getKey(), 'position' => 3]], $change->order);
        $this->assertSame([['id' => $moved->getKey(), 'position' => 2, 'previous_position' => null]], $change->changed);
        $this->assertGreaterThan(3, $change->revision);
        $this->assertSame($shape->storedRevision($destination), $change->revision);
        $this->assertSame($change->revision, $destination->concurrencyRevision($shape->revision()));
        $this->assertFalse($destination->isDirty());

        $this->assertNotNull($change->source);
        $this->assertSame($source, $change->source->relation->parent);
        $this->assertSame([['id' => $first->getKey(), 'position' => 1], ['id' => $third->getKey(), 'position' => 2]], $change->source->order);
        $this->assertSame([['id' => $moved->getKey(), 'position' => null, 'previous_position' => 2]], $change->source->changed);
        $this->assertGreaterThan(4, $change->source->revision);
        $this->assertSame($shape->storedRevision($source), $change->source->revision);
        $this->assertSame($change->source->revision, $source->concurrencyRevision($shape->revision()));
        $this->assertFalse($source->isDirty());

        $this->assertSame($destination->getKey(), $moved->getAttribute($shape->foreignKey()));
        $this->assertSame($destination, $moved->getRelation($shape->parentRelation()));
        $this->assertSame($destination, $moved->orderedRelation()->parent);
        $this->assertSame(2, $moved->getAttribute('position'));
        $this->assertFalse($moved->isDirty());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_locks_both_parents_in_key_order_before_a_move_between_them(Shape $shape): void
    {
        $destination = $shape->createParent();
        [$source, [$moved]] = $shape->with(1);
        DB::enableQueryLog();

        $moved->move(Placement::first()->in($destination), expected: null);

        $statements = DB::getQueryLog();
        $written = array_find_key($statements, fn (array $statement): bool => str_starts_with($statement['query'], sprintf('update "%s"', $shape->memberTable())));
        $locks = array_filter($statements, fn (array $statement): bool => $this->locksParent($shape, $statement['query']));

        $this->assertSame([$destination->getKey(), $source->getKey()], array_values(array_map(fn (array $statement): mixed => $statement['bindings'][0], $locks)));
        $this->assertIsInt($written, 'The member was never written.');
        $this->assertLessThan($written, max(array_keys($locks)));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_stale_move_and_changes_nothing(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);

        $this->assertRejected($shape, StaleConcurrencyRevision::class, fn (): OrderChange => $third->move(Placement::first(), expected: 3), $parent);
        $this->assertOrder($shape, [$first->getKey(), $second->getKey(), $third->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_compares_the_expected_revision_with_the_parent_that_a_member_leaves(Shape $shape): void
    {
        [$source, [$moved]] = $shape->with(1);
        [$destination] = $shape->with(2);

        $this->assertRejected($shape, StaleConcurrencyRevision::class, fn (): OrderChange => $moved->move(Placement::first()->in($destination), expected: 3), $source, $destination);

        $moved->move(Placement::first()->in($destination), expected: 2);

        $this->assertSame($destination->getKey(), $moved->getAttribute($shape->foreignKey()));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_moves_without_a_comparison_when_nothing_is_expected(Shape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);
        $shape->setStoredRevision($parent, 40);

        $change = $second->move(Placement::first(), expected: null);

        $this->assertOrder($shape, [$second->getKey(), $first->getKey()], $parent);
        $this->assertGreaterThan(40, $change->revision);
        $this->assertSame($shape->storedRevision($parent), $change->revision);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_move_anchored_on_the_moved_member(Shape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);

        $this->assertRejected($shape, InvalidAnchor::class, fn (): OrderChange => $second->move(Placement::before($this->key($second)), expected: 3), $parent);
        $this->assertOrder($shape, [$first->getKey(), $second->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_move_anchored_outside_its_destination(Shape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);
        [$other, [$foreign]] = $shape->with(1);

        $this->assertRejected($shape, InvalidAnchor::class, fn (): OrderChange => $second->move(Placement::before($this->key($foreign)), expected: 3), $parent, $other);
        $this->assertOrder($shape, [$first->getKey(), $second->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_changes_neither_parent_when_a_move_between_them_fails(Shape $shape): void
    {
        [$source, [$first, $moved]] = $shape->with(2);
        [$destination, [$member]] = $shape->with(1);

        $this->assertRejected($shape, InvalidAnchor::class, fn (): OrderChange => $moved->move(Placement::before($this->key($first))->in($destination), expected: 3), $source, $destination);
        $this->assertOrder($shape, [$first->getKey(), $moved->getKey()], $source);
        $this->assertOrder($shape, [$member->getKey()], $destination);
        $this->assertSame($source->getKey(), $moved->getAttribute($shape->foreignKey()));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_move_of_a_member_moved_since_it_was_read(Shape $shape): void
    {
        [$source, [$first, $moved]] = $shape->with(2);
        [$elsewhere, [$member]] = $shape->with(1);
        $stale = $shape->find($this->key($moved));

        $moved->move(Placement::last()->in($elsewhere), expected: 3);

        $this->assertSame(3, $shape->storedRevision($elsewhere));
        $this->assertRejected($shape, StaleConcurrencyRevision::class, fn (): OrderChange => $stale->move(Placement::first(), expected: 3), $source, $elsewhere);
        $this->assertOrder($shape, [$first->getKey()], $source);
        $this->assertOrder($shape, [$member->getKey(), $moved->getKey()], $elsewhere);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_moves_a_member_from_where_it_is_now_when_nothing_is_expected(Shape $shape): void
    {
        [$source, [$first, $moved]] = $shape->with(2);
        [$elsewhere, [$member]] = $shape->with(1);
        $stale = $shape->find($this->key($moved));

        $moved->move(Placement::last()->in($elsewhere), expected: null);

        $change = $stale->move(Placement::first(), expected: null);

        $this->assertSame($elsewhere->getKey(), $change->relation->parent->getKey());
        $this->assertOrder($shape, [$first->getKey()], $source);
        $this->assertOrder($shape, [$moved->getKey(), $member->getKey()], $elsewhere);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_move_of_a_member_deleted_since_it_was_read(Shape $shape): void
    {
        [$parent, [$first, $deleted]] = $shape->with(2);

        $shape->find($this->key($deleted))->delete();

        $this->assertRejected($shape, StaleConcurrencyRevision::class, fn (): OrderChange => $deleted->move(Placement::first(), expected: 3), $parent);
        $this->assertRejected($shape, LogicException::class, fn (): OrderChange => $deleted->move(Placement::first(), expected: null), $parent);
        $this->assertOrder($shape, [$first->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_destination_that_does_not_order_the_member(Shape $shape): void
    {
        [$parent, [$member]] = $shape->with(1);
        $stranger = $shape->stranger();

        $this->assertRejected($shape, LogicException::class, fn (): OrderChange => $member->move(Placement::first()->in($stranger), expected: null), $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_refuses_to_move_a_member_that_is_not_saved(Shape $shape): void
    {
        $member = $shape->newMember($shape->createParent());

        try {
            $member->move(Placement::first(), expected: null);
            $this->fail('A member moved before it was saved.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('must be saved before it moves', $exception->getMessage());
        }
    }

    /**
     * @param  Closure(list<Model&Ordered>, null|int): OrderChange  $move
     */
    #[DataProvider('unchangedMoves')]
    #[Test]
    public function it_writes_nothing_for_a_move_that_keeps_the_order(Shape $shape, int $count, Closure $move): void
    {
        [$parent, $members] = $shape->with($count);
        $revision = $shape->storedRevision($parent);
        $keys = $shape->rankKeys($parent);
        $ids = array_map(fn (Model $member): mixed => $member->getKey(), $members);
        DB::enableQueryLog();

        $change = $move($members, $revision);

        $this->assertSame([], $this->writes());
        $this->assertSame([], $change->changed);
        $this->assertSame($revision, $change->revision);
        $this->assertSame(array_map(fn (mixed $id, int $index): array => ['id' => $id, 'position' => $index + 1], $ids, array_keys($ids)), $change->order);
        $this->assertSame($revision, $shape->storedRevision($parent));
        $this->assertSame($keys, $shape->rankKeys($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rebalances_the_keys_rather_than_move_a_member_past_the_longest_key(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        [$moving, $other] = [$third, $second];
        $longest = 0;
        $rebalanced = false;

        for ($attempt = 0; ! $rebalanced && $attempt < 1000; $attempt++) {
            [$moving, $other] = $attempt % 2 === 0 ? [$third, $second] : [$second, $third];

            $change = $moving->move(Placement::after($this->key($first)), expected: null);

            $this->assertSame([$first->getKey(), $moving->getKey(), $other->getKey()], array_column($change->order, 'id'));
            $this->assertSame([['id' => $moving->getKey(), 'position' => 2, 'previous_position' => 3]], $change->changed);

            $length = strlen($this->rankKey($moving));
            $rebalanced = $length < $longest;
            $longest = $length;
        }

        $this->assertTrue($rebalanced);
        $this->assertLessThanOrEqual(OrderedRelation::MAX_KEY_LENGTH, $shape->longestRankKey($parent));
        $this->assertOrder($shape, [$first->getKey(), $moving->getKey(), $other->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rebalances_the_destination_of_a_move_between_parents(Shape $shape): void
    {
        [$source, [$staying, $moved]] = $shape->with(2);
        [$destination, [$first, $next]] = $shape->with(2);
        $inserted = [$next->getKey()];

        while (strlen(FractionalIndex::keyBetween($this->rankKey($first), $this->rankKey($next))) <= OrderedRelation::MAX_KEY_LENGTH) {
            $next = $shape->add($destination, Placement::after($this->key($first)));
            array_unshift($inserted, $next->getKey());
        }

        $longest = $shape->longestRankKey($destination);

        $change = $moved->move(Placement::after($this->key($first))->in($destination), expected: null);

        $this->assertLessThan($longest, $shape->longestRankKey($destination));
        $this->assertSame([['id' => $moved->getKey(), 'position' => 2, 'previous_position' => null]], $change->changed);
        $this->assertOrder($shape, [$first->getKey(), $moved->getKey(), ...$inserted], $destination);
        $this->assertOrder($shape, [$staying->getKey()], $source);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_assigns_a_rank_key_to_each_attached_pivot(PivotShape $shape): void
    {
        $parent = $shape->createParent();
        [$first, $second] = [Customer::create(), Customer::create()];
        DB::enableQueryLog();

        $shape->customers($parent)->attach($first);
        $shape->customers($parent)->attach($second);

        $pivots = [$shape->pivotOf($parent, $first), $shape->pivotOf($parent, $second)];

        $this->assertParentLockedBefore($shape, sprintf('insert into "%s"', $shape->memberTable()));
        $this->assertOrder($shape, [$pivots[0]->getKey(), $pivots[1]->getKey()], $parent);
        $this->assertSame([$first->id, $second->id], $shape->customers($parent)->orderBy($shape->memberTable().'.rank_key')->pluck('customers.id')->all());
        $this->assertSame(3, $shape->storedRevision($parent));
        $this->assertSame(3, $parent->concurrencyRevision($shape->revision()));
        $this->assertFalse($parent->isDirty());
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_takes_a_detached_pivot_out_of_the_relation(PivotShape $shape): void
    {
        $parent = $shape->createParent();
        [$first, $second, $third] = [Customer::create(), Customer::create(), Customer::create()];
        $shape->customers($parent)->attach([$first->id, $second->id, $third->id]);
        $pivots = array_map(fn (Customer $customer): Model => $shape->pivotOf($parent, $customer), [$first, $second, $third]);
        DB::enableQueryLog();

        $this->assertSame(1, $shape->customers($parent)->detach($second));

        $this->assertParentLockedBefore($shape, sprintf('update "%s"', $shape->memberTable()));
        $this->assertSoftDeleted($shape->memberTable(), ['id' => $pivots[1]->getKey(), 'rank_key' => null]);
        $this->assertOrder($shape, [$pivots[0]->getKey(), $pivots[2]->getKey()], $parent);
        $this->assertSame([$pivots[0]->getKey(), $pivots[2]->getKey()], $shape->members($parent));
        $this->assertSame($shape->storedRevision($parent), $parent->concurrencyRevision($shape->revision()));
        $this->assertFalse($parent->isDirty());
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_attaches_a_detached_customer_again_at_the_end(PivotShape $shape): void
    {
        $parent = $shape->createParent();
        [$first, $second] = [Customer::create(), Customer::create()];
        $shape->customers($parent)->attach([$first->id, $second->id]);
        $detached = $shape->pivotOf($parent, $first);

        $shape->customers($parent)->detach($first);
        $shape->customers($parent)->attach($first);

        $attached = $shape->pivotOf($parent, $first);

        $this->assertNotSame($detached->getKey(), $attached->getKey());
        $this->assertOrder($shape, [$shape->pivotOf($parent, $second)->getKey(), $attached->getKey()], $parent);
    }

    #[Test]
    public function it_increments_only_the_order_revision_of_the_parent_type_that_a_scene_names(): void
    {
        $tour = Tour::create();
        $listing = Listing::create(['id' => $tour->id]);

        $inTour = $this->addScene($tour);

        $this->assertSame(2, $tour->concurrencyRevision(TourRevision::ScenesOrder));
        $this->assertSame(1, $this->sceneRevision($listing));

        $inListing = $this->addScene($listing);

        $this->assertSame(2, $this->sceneRevision($tour));
        $this->assertSame(2, $listing->concurrencyRevision(ListingRevision::ScenesOrder));
        $this->assertSame([$inTour->id], $tour->orderedRelation(TourRevision::ScenesOrder)->query()->pluck('id')->all());
        $this->assertSame([$inListing->id], $listing->orderedRelation(ListingRevision::ScenesOrder)->query()->pluck('id')->all());
        $this->assertSame(ListingRevision::ScenesOrder, Scene::query()->findOrFail($inListing->id)->orderedRelation()->revision);
        $this->assertInstanceOf(Listing::class, Scene::query()->findOrFail($inListing->id)->orderedRelation()->parent);
    }

    #[Test]
    public function it_moves_a_scene_between_parents_of_two_types(): void
    {
        $tour = Tour::create();
        $listing = Listing::create();
        $staying = $this->addScene($tour);
        $moved = $this->addScene($tour);
        $member = $this->addScene($listing);

        $change = $moved->move(Placement::first()->in($listing), expected: 3);

        $this->assertSame($listing, $change->relation->parent);
        $this->assertSame([['id' => $moved->id, 'position' => 1], ['id' => $member->id, 'position' => 2]], $change->order);
        $this->assertNotNull($change->source);
        $this->assertSame($tour, $change->source->relation->parent);
        $this->assertSame([['id' => $staying->id, 'position' => 1]], $change->source->order);
        $this->assertSame($this->sceneRevision($tour), $tour->concurrencyRevision(TourRevision::ScenesOrder));
        $this->assertSame(4, $this->sceneRevision($tour));
        $this->assertSame($this->sceneRevision($listing), $listing->concurrencyRevision(ListingRevision::ScenesOrder));
        $this->assertSame(3, $this->sceneRevision($listing));
        $this->assertSame($listing->getMorphClass(), $moved->sceneable_type);
        $this->assertSame($listing->id, $moved->sceneable_id);
        $this->assertSame(['sceneable_type' => $listing->getMorphClass(), 'sceneable_id' => $listing->id], (array) DB::table('scenes')->where('id', $moved->id)->first(['sceneable_type', 'sceneable_id']));
        $this->assertFalse($moved->relationLoaded('sceneable'));
        $this->assertSame($listing, $moved->orderedRelation()->parent);
        $this->assertSame(1, $moved->position);
    }

    #[Test]
    public function it_lets_one_of_two_concurrent_moves_with_one_expected_revision_succeed(): void
    {
        $racer = $this->racer();
        [$section, [$first, $second, $third]] = $this->racingSectionWith(3);
        $rivals = [];

        try {
            $racer->beginTransaction();

            $change = $third->move(Placement::first(), expected: 4);

            $rivals[] = $this->startMove($second, $section, 4);
            $this->awaitLockWait(1, ...$rivals);

            $racer->commit();

            $result = $rivals[0]->wait();

            $this->assertSame('stale', $result->output(), $result->errorOutput());
            $this->assertSame($this->sectionRevision($section), $change->revision);
            $this->assertOrder(new HasManyShape, [$third->id, $first->id, $second->id], $section);
        } finally {
            $this->finishRace($rivals, $section);
        }
    }

    #[DataProvider('lockedSections')]
    #[Test]
    public function it_finishes_opposite_moves_between_two_parents_without_a_deadlock(int $locked): void
    {
        $racer = $this->racer();
        [$first, [$leaving, $stayingFirst]] = $this->racingSectionWith(2);
        [$second, [$arriving, $stayingSecond]] = $this->racingSectionWith(2);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('sections')->where('id', [$first, $second][$locked]->id)->lockForUpdate()->first();

            $rivals[] = $this->startMove($arriving, $first, 3);
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startMove($leaving, $second, 3);
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $outputs = array_map(fn (InvokedProcess $rival): string => $rival->wait()->output(), $rivals);

            $this->assertSame(['moved', 'stale'], $outputs, implode(PHP_EOL, array_map(fn (InvokedProcess $rival): string => $rival->errorOutput(), $rivals)));
            $this->assertOrder(new HasManyShape, [$arriving->id, $leaving->id, $stayingFirst->id], $first);
            $this->assertOrder(new HasManyShape, [$stayingSecond->id], $second);
        } finally {
            $this->finishRace($rivals, $first, $second);
        }
    }

    #[Test]
    public function it_deletes_a_member_from_the_parent_it_moved_to_while_the_delete_waited(): void
    {
        $racer = $this->racer();
        [$source, [$moved, $staying]] = $this->racingSectionWith(2);
        [$destination] = $this->racingSectionWith(0);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('sections')->where('id', $source->id)->lockForUpdate()->first();

            $rivals[] = $this->startDelete($moved);
            $this->awaitLockWait(1, ...$rivals);

            $moved->move(Placement::first()->in($destination), expected: 3);

            $racer->commit();

            $result = $rivals[0]->wait();

            $this->assertSame('deleted', $result->output(), $result->errorOutput());
            $this->assertSoftDeleted('assets', ['id' => $moved->id]);
            $this->assertSame(4, $this->sectionRevision($source));
            $this->assertGreaterThan(2, $this->sectionRevision($destination));
            $this->assertOrder(new HasManyShape, [$staying->id], $source);
            $this->assertOrder(new HasManyShape, [], $destination);
        } finally {
            $this->finishRace($rivals, $source, $destination);
        }
    }

    #[Test]
    public function it_locks_the_parent_that_a_member_moves_to_after_its_delete_read_the_old_one(): void
    {
        $racer = $this->racer();
        config()->set('database.connections.mover', config('database.connections.pgsql'));
        [$source, [$asset]] = $this->racingSectionWith(1);
        [$destination] = $this->racingSectionWith(0);
        $deleting = Asset::on('racer')->findOrFail($asset->id);
        $moved = false;

        try {
            DB::listen(function (QueryExecuted $query) use (&$moved, $asset, $destination): void {
                if ($moved || $query->connectionName !== 'racer' || ! str_starts_with($query->sql, 'select "section_id" from "assets"')) {
                    return;
                }

                $moved = true;

                Asset::on('mover')->findOrFail($asset->id)->move(Placement::first()->in(Section::on('mover')->findOrFail($destination->id)), expected: null);
            });

            $racer->enableQueryLog();

            $deleting->delete();

            $statements = $racer->getQueryLog();
            $written = array_find_key($statements, fn (array $statement): bool => str_starts_with($statement['query'], 'update "assets"'));
            $locks = array_filter($statements, fn (array $statement, int $index): bool => $index < $written && str_contains($statement['query'], 'from "sections"') && str_ends_with($statement['query'], 'for no key update'), ARRAY_FILTER_USE_BOTH);

            $this->assertTrue($moved, 'The member never moved while the delete ran.');
            $this->assertIsInt($written, 'The member was never written.');
            $this->assertNotSame([], $locks, 'No parent was locked before the write.');
            $this->assertSame($destination->id, end($locks)['bindings'][0]);
            $this->assertSoftDeleted('assets', ['id' => $asset->id]);
            $this->assertOrder(new HasManyShape, [], $destination);
        } finally {
            $this->finishRace([], $source, $destination);
        }
    }

    private function key(Model $member): int|string
    {
        $key = $member->getKey();

        $this->assertTrue(is_int($key) || is_string($key));

        return $key;
    }

    private function rankKey(Model $member): string
    {
        $key = $member->getAttribute('rank_key');

        $this->assertIsString($key);

        return $key;
    }

    private function addScene(Tour|Listing $parent): Scene
    {
        $scene = new Scene;
        $scene->sceneable()->associate($parent);
        $scene->save();

        return $scene;
    }

    private function sceneRevision(Tour|Listing $parent): int
    {
        return (int) DB::table($parent->getTable())->where('id', $parent->id)->value('scenes_order_concurrency_revision');
    }

    /**
     * @param  list<mixed>  $expected
     */
    private function assertOrder(Shape $shape, array $expected, Model $parent): void
    {
        $positions = $shape->positions($parent);

        $this->assertSame(array_map(fn (int $index): int => $index + 1, array_keys($expected)), array_values($positions));
        $this->assertSame($expected, array_keys($positions));
    }

    private function locksParent(Shape $shape, string $query): bool
    {
        return str_contains($query, sprintf('from "%s"', $shape->parentTable())) && str_ends_with($query, 'for no key update');
    }

    private function assertParentLockedBefore(Shape $shape, string $write, null|Model $parent = null): void
    {
        $statements = DB::getQueryLog();

        $lock = array_find_key($statements, fn (array $statement): bool => $this->locksParent($shape, $statement['query'])
            && ($parent === null || $statement['bindings'] === [$parent->getKey()]));
        $written = array_find_key($statements, fn (array $statement): bool => str_starts_with($statement['query'], $write));

        $this->assertIsInt($lock, 'The parent row was never locked.');
        $this->assertIsInt($written, 'The item was never written.');
        $this->assertLessThan($written, $lock);
    }

    /**
     * @param  class-string<Throwable>  $exception
     * @param  Closure(): mixed  $change
     */
    private function assertRejected(Shape $shape, string $exception, Closure $change, Model ...$parents): void
    {
        $before = array_map(fn (Model $parent): array => [$shape->storedRevision($parent), $shape->rankKeys($parent)], $parents);
        $thrown = null;

        try {
            $change();
        } catch (Throwable $thrown) {
        }

        $this->assertInstanceOf($exception, $thrown);
        $this->assertSame($before, array_map(fn (Model $parent): array => [$shape->storedRevision($parent), $shape->rankKeys($parent)], $parents));
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

    private function sectionRevision(Section $section): int
    {
        return (new HasManyShape)->storedRevision($section);
    }

    private function racer(): Connection
    {
        config()->set('database.connections.racer', config('database.connections.pgsql'));
        config()->set('database.connections.observer', config('database.connections.pgsql'));

        return DB::connection('racer');
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

    private function startMove(Asset $asset, Section $destination, int $expected): InvokedProcess
    {
        return Process::env($this->databaseEnvironment())->start([
            PHP_BINARY,
            dirname(__DIR__, 6).'/tests/Fixtures/Support/Concurrency/move.php',
            (string) $asset->id,
            (string) $destination->id,
            (string) $expected,
        ]);
    }

    private function startDelete(Asset $asset): InvokedProcess
    {
        return Process::env($this->databaseEnvironment())->start([
            PHP_BINARY,
            dirname(__DIR__, 6).'/tests/Fixtures/Support/Concurrency/delete.php',
            (string) $asset->id,
        ]);
    }

    private function awaitLockWait(int $waiters, InvokedProcess ...$rivals): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            foreach ($rivals as $rival) {
                $this->assertTrue($rival->running(), $rival->errorOutput());
            }

            $waiting = intval(DB::connection('observer')->scalar("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'"));

            if ($waiting >= $waiters) {
                return;
            }

            usleep(10_000);
        }

        $this->fail('The rivals never waited on the row locks.');
    }

    /**
     * @param  list<InvokedProcess>  $rivals
     */
    private function finishRace(array $rivals, Section ...$sections): void
    {
        $racer = DB::connection('racer');

        if ($racer->transactionLevel() > 0) {
            $racer->rollBack();
        }

        foreach ($rivals as $rival) {
            $rival->wait();
        }

        $ids = array_map(fn (Section $section): int => $section->id, $sections);

        $racer->table('assets')->whereIn('section_id', $ids)->delete();
        $racer->table('sections')->whereIn('id', $ids)->delete();
    }

    /**
     * @return array<string, string>
     */
    private function databaseEnvironment(): array
    {
        return [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => config()->string('database.connections.pgsql.host'),
            'DB_PORT' => config()->string('database.connections.pgsql.port'),
            'DB_DATABASE' => config()->string('database.connections.pgsql.database'),
            'DB_USERNAME' => config()->string('database.connections.pgsql.username'),
            'DB_PASSWORD' => config()->string('database.connections.pgsql.password'),
        ];
    }
}
