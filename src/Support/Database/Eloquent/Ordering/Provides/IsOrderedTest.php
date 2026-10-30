<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Events\Reordered;
use Support\Database\Eloquent\Ordering\Exceptions\InvalidAnchor;
use Support\Database\Eloquent\Ordering\Exceptions\MembershipMismatch;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Support\Database\Eloquent\Ordering\OrderChange;
use Support\Database\Eloquent\Ordering\OrderedCollection;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Placement;
use Support\Database\Eloquent\Ordering\PositionChange;
use Tests\Fixtures\Support\Albums\Album;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Boards\Board;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Events\ModelEvents;
use Tests\Fixtures\Support\Events\ModelUpdated;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingRevision;
use Tests\Fixtures\Support\Photos\Photo;
use Tests\Fixtures\Support\Playlists\Playlist;
use Tests\Fixtures\Support\Scenes\Scene;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Shapes\HasManyShape;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Shapes\Reparenting;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Tours\TourRevision;
use Tests\Fixtures\Support\Tracks\Track;
use Tests\Fixtures\Support\Videos\Video;
use Tests\TestCase;
use Throwable;

#[CoversClass(InvalidAnchor::class)]
#[CoversClass(Placement::class)]
#[CoversTrait(IsOrdered::class)]
class IsOrderedTest extends TestCase
{
    /** @var list<array{mixed, list<int|string>, list<array{int|string, null|int, null|int}>, int}> */
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
            'a move into the parent it is in' => [2, fn (array $members, null|int $expected): OrderChange => $members[1]->move(Placement::last()->in($members[1]->orderingRelation()->parent), $expected)],
            'a move that expects nothing' => [3, fn (array $members, null|int $expected): OrderChange => $members[1]->move(Placement::after($members[0]->getKey()), null)],
        ]);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function staleMoves(): iterable
    {
        return Shape::crossed([
            'a move that writes' => [Placement::first(), 1],
            'a move that keeps the order' => [Placement::last(), 2],
        ]);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function listenerWrites(): iterable
    {
        return Shape::crossed([
            'a saving listener of the member' => [fn (Model $parent, Model $moved): Model => $moved, 'saving'],
            'an updating listener of the member' => [fn (Model $parent, Model $moved): Model => $moved, 'updating'],
            'an updating listener of the parent' => [fn (Model $parent, Model $moved): Model => $parent, 'updating'],
        ]);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function packageWrites(): iterable
    {
        yield from Shape::crossed([
            'a create' => [static function (Shape $shape): array {
                [$parent] = $shape->with(1);

                return [[$parent], fn (): Model => $shape->add($parent)];
            }],
            'a create that rebalances' => [static function (Shape $shape): array {
                [$parent, [$first, $second]] = $shape->with(2);
                $shape->crowd($first, $second);

                return [[$parent], fn (): Model => $shape->add($parent, Placement::after(self::keyOf($first)))];
            }],
            'a move within a parent' => [static function (Shape $shape): array {
                [$parent, [, , $moved]] = $shape->with(3);

                return [[$parent], fn (): OrderChange => $moved->move(Placement::first(), expected: null)];
            }],
            'a move that rebalances' => [static function (Shape $shape): array {
                [$parent, [$first, $second, $moved]] = $shape->with(3);
                $shape->crowd($first, $second);

                return [[$parent], fn (): OrderChange => $moved->move(Placement::after(self::keyOf($first)), expected: null)];
            }],
            'a move between parents' => [static function (Shape $shape): array {
                [$source, [, $moved]] = $shape->with(2);
                [$destination] = $shape->with(1);

                return [[$source, $destination], fn (): OrderChange => $moved->move(Placement::last()->in($destination), expected: null)];
            }],
            'a save that changes the parent' => [static function (Shape $shape): array {
                [$source, [, $moved]] = $shape->with(2);
                [$destination] = $shape->with(1);
                $shape->associate($moved, $destination);

                return [[$source, $destination], fn (): bool => $moved->save()];
            }],
            'a reorder' => [static function (Shape $shape): array {
                [$parent, [$first, $second, $third]] = $shape->with(3);

                return [[$parent], fn (): OrderChange => $shape->orderedRelation($parent)->reorder([self::keyOf($third), self::keyOf($first), self::keyOf($second)], expected: null)];
            }],
            'a soft delete' => [static function (Shape $shape): array {
                [$parent, [, $member]] = $shape->with(2);

                return [[$parent], fn (): mixed => $member->delete()];
            }],
            'a force delete' => [static function (Shape $shape): array {
                [$parent, [, $member]] = $shape->with(2);
                assert(method_exists($member, 'forceDelete'));

                return [[$parent], fn (): mixed => $member->forceDelete()];
            }],
            'a restore' => [static function (Shape $shape): array {
                [$parent, [, $member]] = $shape->with(2);
                $member->delete();
                assert(method_exists($member, 'restore'));

                return [[$parent], fn (): mixed => $member->restore()];
            }],
        ]);

        foreach (PivotShape::pivots() as $name => [$shape]) {
            yield "{$name}, an attach" => [$shape, static function (Shape $shape): array {
                assert($shape instanceof PivotShape);
                [$parent] = $shape->with(1);

                return [[$parent], function () use ($shape, $parent): void {
                    $shape->customers($parent)->attach(Customer::create());
                }];
            }];

            yield "{$name}, a detach" => [$shape, static function (Shape $shape): array {
                assert($shape instanceof PivotShape);
                [$parent, [, $member]] = $shape->with(2);

                return [[$parent], fn (): int => $shape->customers($parent)->detach($member->getAttribute('customer_id'))];
            }];
        }
    }

    /**
     * @return iterable<string, array{Closure(Video, Section): mixed}>
     */
    public static function writesWithoutSoftDeletes(): iterable
    {
        yield 'a save that changes the parent' => [fn (Video $video, Section $destination): mixed => $video->section()->associate($destination)];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function lockedSections(): iterable
    {
        yield 'while the first parent is locked' => [0];
        yield 'while the second parent is locked' => [1];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lateSaveListeners(): iterable
    {
        yield 'an updating listener' => ['updating'];
        yield 'a saving listener registered after boot' => ['saving'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lateCreateListeners(): iterable
    {
        yield 'a saving listener registered after boot' => ['saving'];
        yield 'a creating listener registered after boot' => ['creating'];
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function lateCopyListeners(): iterable
    {
        return Shape::crossed(self::lateSaveListeners());
    }

    /**
     * @return iterable<string, array{Closure(): array{Model&OrdersRelations&Revisioned, Model&Ordered, list<Model&Ordered>, array<string, mixed>}}>
     */
    public static function departures(): iterable
    {
        return Reparenting::departures();
    }

    /**
     * @return iterable<string, array{Closure(): array{Model&OrdersRelations&Revisioned, Model&Ordered, list<Model&Ordered>, array<string, mixed>}}>
     */
    public static function arrivals(): iterable
    {
        return Reparenting::arrivals();
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function reorderingWrites(): iterable
    {
        return Shape::crossed([
            'a move' => [static function (Shape $shape): array {
                [, [, , $member]] = $shape->with(3);

                return [$member, fn (Model&Ordered $member): mixed => $member->move(Placement::first(), expected: null), 3, 1];
            }],
            'a save that changes the parent' => [static function (Shape $shape): array {
                [, [$member]] = $shape->with(2);
                [$destination] = $shape->with(1);

                return [$member, fn (Model&Ordered $member): mixed => $member->update([$shape->foreignKey() => $destination->getKey()]), 1, 2];
            }],
            'a soft delete' => [static function (Shape $shape): array {
                [, [, $member]] = $shape->with(2);

                return [$member, fn (Model&Ordered $member): mixed => $member->delete(), 2, null];
            }],
            'a restore' => [static function (Shape $shape): array {
                [, [$member]] = $shape->with(3);
                $member->delete();

                return [$member, static function (Model&Ordered $member): mixed {
                    assert(method_exists($member, 'restore'));

                    return $member->restore();
                }, null, 3];
            }],
        ]);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function positionedWrites(): iterable
    {
        return Shape::crossed([
            'a move' => [fn (Shape $shape, Model&Ordered $member, Model $destination): mixed => $member->move(Placement::first(), expected: null)],
            'a move between parents' => [fn (Shape $shape, Model&Ordered $member, Model $destination): mixed => $member->move(Placement::first()->in($destination), expected: null)],
            'a save that changes the parent' => [fn (Shape $shape, Model&Ordered $member, Model $destination): mixed => $member->update([$shape->foreignKey() => $destination->getKey()])],
        ]);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_reads_a_selected_position_without_a_query_and_counts_one_it_did_not_select(Shape $shape): void
    {
        [$parent, [, $second]] = $shape->with(3);
        $selected = $shape->orderedRelation($parent)->query()->withPositions()->get();
        $unselected = $shape->find($this->key($second));
        DB::enableQueryLog();

        $this->assertSame([1, 2, 3], $selected->map(fn (Model $result): mixed => $shape->item($result)->getAttribute('position'))->all());
        $this->assertSame([], DB::getQueryLog());
        $this->assertSame(2, $unselected->getAttribute('position'));
        $this->assertNotSame([], DB::getQueryLog());
    }

    #[DataProvider('reorderingWrites')]
    #[Test]
    public function it_counts_the_position_again_after_a_write_that_changes_its_order(Shape $shape, Closure $case): void
    {
        [$member, $write, $before, $after] = $case($shape);
        (new OrderedCollection([$member]))->loadPositions();
        DB::enableQueryLog();

        $this->assertSame($before, $member->getAttribute('position'));
        $this->assertSame([], DB::getQueryLog());

        $write($member);

        $this->assertArrayNotHasKey(OrderedRelation::POSITION, $member->getAttributes());
        $this->assertSame($after, $member->getAttribute('position'));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_saves_a_member_with_a_selected_position_without_writing_it(Shape $shape): void
    {
        [$parent] = $shape->with(2);
        $member = $shape->item($shape->orderedRelation($parent)->query()->withPositions()->get()->firstOrFail(fn (Model $result): bool => $shape->item($result)->getAttribute('position') === 2));
        DB::enableQueryLog();

        $member->setAttribute('title', 'Dusk');

        $this->assertTrue($member->save());
        $this->assertFalse($member->isDirty());
        $this->assertSame(2, $member->getAttribute('position'));
        $this->assertSame('Dusk', DB::table($shape->memberTable())->where('id', $member->getKey())->value('title'));
        $this->assertCount(1, $this->writes());
        $this->assertStringNotContainsString('position', $this->writes()[0]);
    }

    #[DataProvider('positionedWrites')]
    #[Test]
    public function it_reports_no_position_in_the_events_of_a_member_with_a_selected_position(Shape $shape, Closure $write): void
    {
        [$parent] = $shape->with(3);
        [$destination] = $shape->with(1);
        $member = $shape->item($shape->orderedRelation($parent)->query()->withPositions()->get()->firstOrFail(fn (Model $result): bool => $shape->item($result)->getAttribute('position') === 3));
        $events = ModelEvents::record();
        DB::enableQueryLog();

        $write($shape, $member, $destination);

        $this->assertNotSame([], $events->fired);

        foreach ($events->fired as [, , , $original, $changed]) {
            $this->assertArrayNotHasKey(OrderedRelation::POSITION, $original);
            $this->assertArrayNotHasKey(OrderedRelation::POSITION, $changed);
        }

        $this->assertStringNotContainsString('"position"', implode(' ', $this->writes()));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_writes_no_selected_position_for_a_copy_of_a_member(Shape $shape): void
    {
        [, [$member]] = $shape->with(1);
        $positioned = $shape->find($this->key($member));
        (new OrderedCollection([$positioned]))->loadPositions();

        $copy = $positioned->replicate();

        if ($shape instanceof PivotShape) {
            $copy->setAttribute('customer_id', Customer::create()->id);
        }

        $this->assertTrue($copy->save());
        $this->assertArrayNotHasKey(OrderedRelation::POSITION, $copy->getAttributes());
        $this->assertSame(2, $copy->getAttribute('position'));
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

    #[DataProvider('pivots')]
    #[Test]
    public function it_places_and_moves_by_an_upper_case_uuid_anchor(PivotShape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);

        $placed = $shape->add($parent, Placement::after(strtoupper((string) $this->key($first))));
        $second->move(Placement::before(strtoupper((string) $this->key($first))), expected: 4);

        $this->assertOrder($shape, [$second->getKey(), $first->getKey(), $placed->getKey()], $parent);
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
    public function it_rejects_a_uuid_anchor_that_names_no_member(Shape $shape): void
    {
        $parent = $shape->createParent();
        $shape->add($parent);

        $this->expectException(InvalidAnchor::class);

        $shape->add($parent, Placement::after('0190a5b2-0000-7000-8000-000000000000'));
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
    public function it_places_a_member_restored_after_a_soft_delete_last(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $first->delete();
        $deleted = $shape->storedRevision($parent);
        assert(method_exists($first, 'restore'));

        $this->assertTrue($first->restore());

        $this->assertSame(3, $first->getAttribute('position'));
        $this->assertSame($this->storedRankKey($shape, $first), $this->rankKey($first));
        $this->assertFalse($first->isDirty());
        $this->assertOrder($shape, [$second->getKey(), $third->getKey(), $first->getKey()], $parent);
        $this->assertSame($deleted + 1, $shape->storedRevision($parent));
        $this->assertSame($shape->storedRevision($parent), $parent->concurrencyRevision($shape->revision()));
        $this->assertFalse($parent->isDirty());
        $this->assertSame($parent, $first->orderingRelation()->parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_places_a_member_restored_after_a_bulk_soft_delete_last(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $kept = $this->storedRankKey($shape, $first);

        $shape->memberClass()::query()->whereKey($first->getKey())->delete();
        $restored = $shape->find($this->key($first));
        assert(method_exists($restored, 'restore'));

        $this->assertSame($kept, $this->rankKey($restored));
        $this->assertTrue($restored->restore());

        $this->assertNull(DB::table($shape->memberTable())->where('id', $first->getKey())->value('deleted_at'));
        $this->assertNotSame($kept, $this->storedRankKey($shape, $restored));
        $this->assertSame($this->storedRankKey($shape, $restored), $this->rankKey($restored));
        $this->assertSame(3, $restored->getAttribute('position'));
        $this->assertFalse($restored->isDirty());
        $this->assertOrder($shape, [$second->getKey(), $third->getKey(), $first->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_places_a_restored_member_last_while_another_member_holds_its_old_key(Shape $shape): void
    {
        [$parent, [$first, $deleted]] = $shape->with(2);
        $kept = $this->storedRankKey($shape, $deleted);

        $shape->memberClass()::query()->whereKey($deleted->getKey())->delete();
        $created = $shape->add($parent);
        $restored = $shape->find($this->key($deleted));
        assert(method_exists($restored, 'restore'));

        $this->assertSame($kept, $this->storedRankKey($shape, $created));
        $this->assertTrue($restored->restore());

        $this->assertNotSame($kept, $this->storedRankKey($shape, $restored));
        $this->assertSame(3, $restored->getAttribute('position'));
        $this->assertOrder($shape, [$first->getKey(), $created->getKey(), $deleted->getKey()], $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_keeps_the_order_for_a_stale_restore_of_a_member_already_restored(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $first->delete();
        $stale = $shape->find($this->key($first));
        $restored = $shape->find($this->key($first));
        assert(method_exists($stale, 'restore') && method_exists($restored, 'restore'));
        $restored->restore();
        $second->move(Placement::last(), null);
        $kept = $this->storedRankKey($shape, $first);
        $revision = $shape->storedRevision($parent);

        $this->assertTrue($stale->restore());

        $this->assertSame($kept, $this->storedRankKey($shape, $first));
        $this->assertSame($kept, $this->rankKey($stale));
        $this->assertSame(2, $stale->getAttribute('position'));
        $this->assertFalse($stale->isDirty());
        $this->assertOrder($shape, [$third->getKey(), $first->getKey(), $second->getKey()], $parent);
        $this->assertSame($revision, $shape->storedRevision($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_counts_the_position_again_after_a_stale_restore_of_a_member_whose_position_it_loaded(Shape $shape): void
    {
        [, [$first]] = $shape->with(3);
        $first->delete();
        $stale = $shape->find($this->key($first));
        (new OrderedCollection([$stale]))->loadPositions();
        $restored = $shape->find($this->key($first));
        assert(method_exists($stale, 'restore') && method_exists($restored, 'restore'));
        $this->assertNull($stale->getAttribute('position'));
        $restored->restore();

        $this->assertTrue($stale->restore());

        $this->assertSame(3, $shape->find($this->key($first))->getAttribute('position'));
        $this->assertSame(3, $stale->getAttribute('position'));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_assigns_no_key_for_a_stale_restore_of_a_force_deleted_member(Shape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);
        $first->delete();
        $stale = $shape->find($this->key($first));
        $shape->find($this->key($first))->forceDelete();
        assert(method_exists($stale, 'restore'));
        $revision = $shape->storedRevision($parent);

        $this->assertTrue($stale->restore());

        $this->assertNull($stale->getAttribute('rank_key'));
        $this->assertNull($stale->getAttribute('position'));
        $this->assertFalse(DB::table($shape->memberTable())->where('id', $first->getKey())->exists());
        $this->assertOrder($shape, [$second->getKey()], $parent);
        $this->assertSame($revision, $shape->storedRevision($parent));
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
        $this->assertSame($parent, $member->orderingRelation()->parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_writes_the_order_revision_into_the_parent_it_holds_after_a_soft_delete(Shape $shape): void
    {
        $parent = $shape->createParent();
        $member = $shape->add($parent);

        $member->delete();

        $this->assertSame(3, $parent->concurrencyRevision($shape->revision()));
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

        $this->assertNotSame($parent, $member->orderingRelation()->parent);
        $this->assertSame(2, $member->orderingRelation()->parent->concurrencyRevision($shape->revision()));
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

        $this->assertSame(SectionRevision::AssetsOrder, $asset->orderingRelation()->revision);
        $this->assertSame(SectionRevision::VideosOrder, $video->orderingRelation()->revision);
        $this->assertSame(1, $asset->getAttribute('position'));
        $this->assertSame(1, $video->position);
        $this->assertSame([$video->id], $section->orderedRelation(SectionRevision::VideosOrder)->query()->pluck('id')->all());
    }

    #[Test]
    public function it_raises_the_own_revision_of_a_revisioned_member_for_a_move_between_parents_only(): void
    {
        $section = Section::create();
        $destination = Section::create();
        $this->addVideo($section);
        $video = $this->addVideo($section);
        $video->setAttribute('title', 'Dusk');

        $video->move(Placement::first(), expected: null);

        $this->assertSame(1, $this->videoRevision($video));
        $this->assertSame(1, $video->concurrencyRevision());
        $this->assertSame(['title' => 'Dusk'], $video->getDirty());

        $video->move(Placement::last()->in($destination), expected: null);

        $this->assertSame(2, $this->videoRevision($video));
        $this->assertSame(2, $video->concurrencyRevision());
        $this->assertSame(['title' => 'Dusk'], $video->getDirty());
        $this->assertSame(3, $video->claimConcurrencyRevision(2));
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
    public function it_keeps_the_requested_placement_through_a_halted_create(Shape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);
        $halt = true;

        $shape->memberClass()::creating(function () use (&$halt): null|bool {
            if (! $halt) {
                return null;
            }

            $halt = false;

            return false;
        });

        $member = $shape->newMember($parent)->placeAt(Placement::first());

        $this->assertFalse($member->save());
        $this->assertTrue($member->save());
        $this->assertOrder($shape, [$member->getKey(), $first->getKey(), $second->getKey()], $parent);
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

    #[DataProvider('shapes')]
    #[Test]
    public function it_places_a_member_whose_foreign_key_an_update_changes_last_in_its_new_parent(Shape $shape): void
    {
        [$source, [$moved, $staying]] = $shape->with(2);
        [$destination, [$removed, $member]] = $shape->with(2);
        $removed->forceDelete();

        $this->assertTrue($moved->update([$shape->foreignKey() => $destination->getKey()]));

        $this->assertSame(2, $moved->getAttribute('position'));
        $this->assertSame($this->storedRankKey($shape, $moved), $this->rankKey($moved));
        $this->assertFalse($moved->isDirty());
        $this->assertOrder($shape, [$member->getKey(), $moved->getKey()], $destination);
        $this->assertOrder($shape, [$staying->getKey()], $source);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_moves_a_member_by_an_update_into_a_parent_whose_member_holds_its_rank_key(Shape $shape): void
    {
        [$source, [$moved]] = $shape->with(1);
        [$destination, [$holder]] = $shape->with(1);

        $this->assertSame($this->storedRankKey($shape, $holder), $this->storedRankKey($shape, $moved));
        $this->assertTrue($moved->update([$shape->foreignKey() => $destination->getKey()]));

        $this->assertOrder($shape, [$holder->getKey(), $moved->getKey()], $destination);
        $this->assertOrder($shape, [], $source);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_writes_both_order_revisions_into_the_parents_it_holds_after_a_reparenting_save(Shape $shape): void
    {
        [$source, [$moved]] = $shape->with(1);
        [$destination] = $shape->with(1);
        $revisions = [$shape->storedRevision($source), $shape->storedRevision($destination)];

        $shape->associate($moved, $destination);
        $this->assertTrue($moved->save());

        $this->assertGreaterThan($revisions[0], $shape->storedRevision($source));
        $this->assertGreaterThan($revisions[1], $shape->storedRevision($destination));
        $this->assertSame($shape->storedRevision($source), $source->concurrencyRevision($shape->revision()));
        $this->assertSame($shape->storedRevision($destination), $destination->concurrencyRevision($shape->revision()));
        $this->assertFalse($source->isDirty());
        $this->assertFalse($destination->isDirty());
        $this->assertSame($destination, $moved->orderingRelation()->parent);
        $this->assertSame(2, $moved->getAttribute('position'));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_places_a_member_without_a_rank_key_last_in_the_parent_a_save_moves_it_to(Shape $shape): void
    {
        [$source, [$moved, $staying]] = $shape->with(2);
        [$destination, [$member]] = $shape->with(1);
        DB::table($shape->memberTable())->where('id', $moved->getKey())->update(['rank_key' => null]);
        $keyless = $shape->find($this->key($moved));

        $this->assertTrue($keyless->update([$shape->foreignKey() => $destination->getKey()]));

        $this->assertSame(2, $keyless->getAttribute('position'));
        $this->assertSame($this->storedRankKey($shape, $keyless), $this->rankKey($keyless));
        $this->assertFalse($keyless->isDirty());
        $this->assertOrder($shape, [$member->getKey(), $moved->getKey()], $destination);
        $this->assertOrder($shape, [$staying->getKey()], $source);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_gives_a_pivot_the_relation_excludes_a_new_last_key_in_the_parent_a_save_moves_it_to(PivotShape $shape): void
    {
        [$source, [$excluded]] = $shape->with(1);
        [$destination, [$holder]] = $shape->with(1);
        Customer::query()->findOrFail($excluded->getAttribute('customer_id'))->delete();

        $this->assertSame($this->storedRankKey($shape, $holder), $this->storedRankKey($shape, $excluded));
        $this->assertTrue($excluded->update([$shape->foreignKey() => $destination->getKey()]));

        $this->assertNull($excluded->getAttribute('position'));
        $this->assertSame($this->storedRankKey($shape, $excluded), $this->rankKey($excluded));
        $this->assertFalse($excluded->isDirty());
        $this->assertSame([$holder->getKey()], $shape->members($destination));
        $this->assertSame([$holder->getKey(), $excluded->getKey()], $this->storedOrder($shape, $destination));
        $this->assertSame([], $this->storedOrder($shape, $source));
    }

    /**
     * @param  Closure(): array{Model&OrdersRelations&Revisioned, Model&Ordered, list<Model&Ordered>, array<string, mixed>}  $reparent
     */
    #[DataProvider('departures')]
    #[Test]
    public function it_clears_the_rank_key_of_a_member_that_a_save_takes_out_of_every_order(Closure $reparent): void
    {
        [, $leaving, [$staying], $attributes] = $reparent();

        $this->assertTrue($leaving->update($attributes));

        $this->assertNull($leaving->getAttribute('rank_key'));
        $this->assertNull(DB::table($leaving->getTable())->where('id', $leaving->getKey())->value('rank_key'));
        $this->assertNull($leaving->getAttribute('position'));
        $this->assertFalse($leaving->isDirty());
        $this->assertSame(1, $staying->getAttribute('position'));
    }

    /**
     * @param  Closure(): array{Model&OrdersRelations&Revisioned, Model&Ordered, list<Model&Ordered>, array<string, mixed>}  $reparent
     */
    #[DataProvider('arrivals')]
    #[Test]
    public function it_places_a_member_that_a_save_gives_a_parent_last_in_that_parent(Closure $reparent): void
    {
        [, $arriving, $members, $attributes] = $reparent();

        $this->assertTrue($arriving->update($attributes));

        $this->assertSame(DB::table($arriving->getTable())->where('id', $arriving->getKey())->value('rank_key'), $this->rankKey($arriving));
        $this->assertFalse($arriving->isDirty());
        $this->assertSame(range(1, count($members) + 1), array_map(fn (Model $member): mixed => $member->getAttribute('position'), [...$members, $arriving]));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_moves_a_member_that_a_stale_restore_reparents_after_another_request_restored_it(Shape $shape): void
    {
        [$source, [$restored]] = $shape->with(1);
        [$destination, [$holder]] = $shape->with(1);
        $restored->delete();
        $stale = $shape->find($this->key($restored));
        assert(method_exists($restored, 'restore') && method_exists($stale, 'restore'));
        $restored->restore();

        $this->assertSame($this->storedRankKey($shape, $holder), $this->storedRankKey($shape, $restored));
        $shape->associate($stale, $destination);
        $this->assertTrue($stale->restore());

        $this->assertSame(2, $stale->getAttribute('position'));
        $this->assertSame($this->storedRankKey($shape, $stale), $this->rankKey($stale));
        $this->assertFalse($stale->isDirty());
        $this->assertOrder($shape, [$holder->getKey(), $restored->getKey()], $destination);
        $this->assertOrder($shape, [], $source);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_keeps_a_member_in_the_parent_it_moved_to_for_a_stale_restore_after_another_request_restored_and_moved_it(Shape $shape): void
    {
        [$source, [$member]] = $shape->with(1);
        [$destination, [$holder]] = $shape->with(1);
        $member->delete();
        $stale = $shape->find($this->key($member));
        assert(method_exists($member, 'restore') && method_exists($stale, 'restore'));
        $member->restore();
        $member->move(Placement::last()->in($destination), expected: null);
        $keys = $shape->rankKeys($destination);
        $revisions = [$shape->storedRevision($source), $shape->storedRevision($destination)];

        $this->assertTrue($stale->restore());

        $this->assertSame($keys, $shape->rankKeys($destination));
        $this->assertSame($revisions, [$shape->storedRevision($source), $shape->storedRevision($destination)]);
        $this->assertSame($destination->getKey(), $stale->getAttribute($shape->foreignKey()));
        $this->assertSame($destination->getKey(), $stale->getRawOriginal($shape->foreignKey()));
        $this->assertTrue($destination->is($stale->orderingRelation()->parent));
        $this->assertSame(2, $stale->getAttribute('position'));
        $this->assertFalse($stale->isDirty());
        $this->assertOrder($shape, [$holder->getKey(), $member->getKey()], $destination);
        $this->assertOrder($shape, [], $source);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_refuses_a_save_that_sets_the_rank_key(Shape $shape): void
    {
        [$parent, [$member]] = $shape->with(2);
        $member->setAttribute('rank_key', 'Zz');

        $this->assertRejected($shape, LogicException::class, fn (): bool => $member->save(), $parent);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_refuses_a_new_member_whose_rank_key_is_set(Shape $shape): void
    {
        $parent = $shape->createParent();
        $member = $shape->newMember($parent);
        $member->setAttribute('rank_key', 'Zz');

        $this->assertRejected($shape, LogicException::class, fn (): bool => $member->save(), $parent);
    }

    /**
     * @param  Closure(Video, Section): mixed  $write
     */
    #[DataProvider('writesWithoutSoftDeletes')]
    #[Test]
    public function it_saves_a_reloaded_member_without_soft_deletes_while_missing_attributes_are_prevented(Closure $write): void
    {
        $source = Section::create();
        $destination = Section::create();
        $video = $this->addVideo($source);

        Model::preventAccessingMissingAttributes();

        try {
            $reloaded = Video::query()->findOrFail($video->id);
            $write($reloaded, $destination);

            $this->assertTrue($reloaded->save());
            $this->assertSame(['section_id' => $reloaded->section_id, 'rank_key' => $reloaded->rank_key], (array) DB::table('videos')->where('id', $video->id)->first(['section_id', 'rank_key']));
            $this->assertSame(1, $reloaded->position);
            $this->assertFalse($reloaded->isDirty());
        } finally {
            Model::preventAccessingMissingAttributes(false);
        }
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_keeps_the_rank_key_of_a_member_that_a_save_returns_to_its_parent_after_a_halted_reparent(Shape $shape): void
    {
        [$source, [$moved, $staying]] = $shape->with(2);
        [$destination] = $shape->with(1);
        $kept = $this->storedRankKey($shape, $moved);
        $halt = true;

        $shape->memberClass()::updating(function () use (&$halt): null|bool {
            if (! $halt) {
                return null;
            }

            $halt = false;

            return false;
        });

        $shape->associate($moved, $destination);
        $this->assertFalse($moved->save());
        $shape->associate($moved, $source);

        $this->assertTrue($moved->save());

        $this->assertSame($kept, $this->storedRankKey($shape, $moved));
        $this->assertSame($kept, $this->rankKey($moved));
        $this->assertFalse($moved->isDirty());
        $this->assertOrder($shape, [$moved->getKey(), $staying->getKey()], $source);
    }

    #[DataProvider('lateSaveListeners')]
    #[Test]
    public function it_refuses_a_listener_that_changes_the_parent_of_a_save_after_it_prepared_the_order(string $event): void
    {
        $shape = new HasManyShape;
        $source = Section::create();
        $destination = Section::create();
        $moving = $shape->add($source);
        $holder = $shape->add($destination);
        $this->assertSame($this->storedRankKey($shape, $moving), $this->storedRankKey($shape, $holder));
        $this->recordReorders();

        Event::listen(sprintf('eloquent.%s: %s', $event, Asset::class), function (Asset $asset) use ($destination): void {
            if ($asset->isDirty('title')) {
                $asset->section_id = $destination->id;
            }
        });

        $item = Asset::query()->findOrFail($moving->getKey());
        $item->title = 'Renamed';

        $this->assertRejected($shape, LogicException::class, fn (): bool => $item->save(), $source, $destination);
        $this->assertSame(['section_id' => $source->id, 'title' => null], (array) DB::table('assets')->where('id', $moving->getKey())->first(['section_id', 'title']));
        $this->assertSame([], $this->reorders);
    }

    #[DataProvider('lateCreateListeners')]
    #[Test]
    public function it_refuses_a_listener_that_changes_the_parent_of_a_create_after_it_prepared_the_order(string $event): void
    {
        $shape = new HasManyShape;
        $source = Section::create();
        $destination = Section::create();
        $shape->add($destination);

        Event::listen(sprintf('eloquent.%s: %s', $event, Asset::class), function (Asset $asset) use ($destination): void {
            $asset->section_id = $destination->id;
        });

        $this->assertRejected($shape, LogicException::class, fn (): Model => $shape->add($source), $source, $destination);
    }

    #[DataProvider('lateCopyListeners')]
    #[Test]
    public function it_refuses_a_listener_that_changes_the_rank_key_of_a_member_that_a_reorder_saves(Shape $shape, string $event): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $this->recordReorders();
        $keys = 0;

        Event::listen(sprintf('eloquent.%s: %s', $event, $shape->memberClass()), function (Model $member) use (&$keys): void {
            $member->setAttribute(OrderedRelation::RANK_KEY, 'z'.++$keys);
        });

        $this->assertRejected(
            $shape,
            LogicException::class,
            fn (): OrderChange => $shape->orderedRelation($parent)->reorder([self::keyOf($third), self::keyOf($first), self::keyOf($second)], expected: null),
            $parent,
        );
        $this->assertSame([], $this->reorders);
    }

    #[DataProvider('lateSaveListeners')]
    #[Test]
    public function it_writes_a_column_outside_the_order_that_a_listener_changes_after_it_prepared_a_save(string $event): void
    {
        $shape = new HasManyShape;
        [$source, [$staying, $moving]] = $shape->with(2);
        [$destination, [$holder]] = $shape->with(1);

        Event::listen(sprintf('eloquent.%s: %s', $event, Asset::class), function (Asset $asset): void {
            $asset->title = 'Renamed';
        });

        $shape->associate($moving, $destination);

        $this->assertTrue($moving->save());
        $this->assertSame('Renamed', DB::table('assets')->where('id', $moving->getKey())->value('title'));
        $this->assertOrder($shape, [$staying->getKey()], $source);
        $this->assertOrder($shape, [$holder->getKey(), $moving->getKey()], $destination);
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
        $this->assertSame($ids, $change->order);
        $this->assertSame([['id' => $this->key($members[$moved]), 'position' => (int) array_search($moved, $expected, true) + 1, 'previousPosition' => $moved + 1]], array_map(get_object_vars(...), $change->changed));
        $this->assertSame($parent, $change->relation->parent);
        $this->assertSame($shape->revision(), $change->relation->revision);
        $this->assertNull($change->source);
        $this->assertSame(6, $change->revision);
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

        $change = $moved->move(Placement::after($this->key($anchor))->in($destination), expected: 3);

        $this->assertOrder($shape, [$first->getKey(), $third->getKey()], $source);
        $this->assertOrder($shape, [$anchor->getKey(), $moved->getKey(), $last->getKey()], $destination);

        $this->assertSame($destination, $change->relation->parent);
        $this->assertSame([$anchor->getKey(), $moved->getKey(), $last->getKey()], $change->order);
        $this->assertSame([['id' => $this->key($moved), 'position' => 2, 'previousPosition' => null]], array_map(get_object_vars(...), $change->changed));
        $this->assertSame(4, $change->revision);
        $this->assertSame($shape->storedRevision($destination), $change->revision);
        $this->assertSame($change->revision, $destination->concurrencyRevision($shape->revision()));
        $this->assertFalse($destination->isDirty());

        $this->assertNotNull($change->source);
        $this->assertSame($source, $change->source->relation->parent);
        $this->assertSame([$first->getKey(), $third->getKey()], $change->source->order);
        $this->assertSame([['id' => $this->key($moved), 'position' => null, 'previousPosition' => 2]], array_map(get_object_vars(...), $change->source->changed));
        $this->assertSame(5, $change->source->revision);
        $this->assertSame($shape->storedRevision($source), $change->source->revision);
        $this->assertSame($change->source->revision, $source->concurrencyRevision($shape->revision()));
        $this->assertFalse($source->isDirty());

        $this->assertSame($destination->getKey(), $moved->getAttribute($shape->foreignKey()));
        $this->assertSame($destination, $moved->getRelation($shape->parentRelation()));
        $this->assertSame($destination, $moved->orderingRelation()->parent);
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
    public function it_compares_the_expected_revision_with_the_parent_that_a_member_enters(Shape $shape): void
    {
        [$source, [$moved]] = $shape->with(1);
        [$destination] = $shape->with(2);

        $this->assertRejected($shape, StaleConcurrencyRevision::class, fn (): OrderChange => $moved->move(Placement::first()->in($destination), expected: 2), $source, $destination);

        $moved->move(Placement::first()->in($destination), expected: 3);

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
        $this->assertSame(41, $change->revision);
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

        $this->assertRejected($shape, InvalidAnchor::class, fn (): OrderChange => $moved->move(Placement::before($this->key($first))->in($destination), expected: 2), $source, $destination);
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

        $moved->move(Placement::last()->in($elsewhere), expected: 2);

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

    #[DataProvider('staleMoves')]
    #[Test]
    public function it_follows_the_parent_a_member_moved_to_when_a_stale_instance_moves_within_it(Shape $shape, Placement $placement, int $position): void
    {
        [$source, [$member]] = $shape->with(1);
        [$destination, [$holder]] = $shape->with(1);
        $stale = $shape->find($this->key($member))->load($shape->parentRelation());
        $shape->find($this->key($member))->move(Placement::last()->in($destination), expected: null);
        $stale->setAttribute('title', 'Dusk');

        $change = $stale->move($placement, expected: null);

        $this->assertTrue($destination->is($change->relation->parent));
        $this->assertSame($destination->getKey(), $stale->getAttribute($shape->foreignKey()));
        $this->assertSame($destination->getKey(), $stale->getRawOriginal($shape->foreignKey()));
        $this->assertSame(['title' => 'Dusk'], $stale->getDirty());
        $this->assertSame($change->relation->parent, $stale->getRelation($shape->parentRelation()));
        $this->assertSame($change->relation->parent, $stale->orderingRelation()->parent);
        $this->assertSame($position, $stale->getAttribute('position'));
        $this->assertOrder($shape, $position === 1 ? [$member->getKey(), $holder->getKey()] : [$holder->getKey(), $member->getKey()], $destination);
        $this->assertOrder($shape, [], $source);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_accepts_the_revision_that_a_stale_instance_moving_within_the_parent_it_moved_to_returns(Shape $shape): void
    {
        [, [$member]] = $shape->with(1);
        [$destination, [$holder]] = $shape->with(1);
        $stale = $shape->find($this->key($member));
        $shape->find($this->key($member))->move(Placement::last()->in($destination), expected: null);

        $change = $stale->move(Placement::first(), expected: null);
        $stale->move(Placement::last(), $change->revision);

        $this->assertOrder($shape, [$holder->getKey(), $member->getKey()], $destination);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_points_the_loaded_parent_of_a_stale_instance_at_the_parent_it_moves_to(Shape $shape): void
    {
        [$source, [$member]] = $shape->with(1);
        $elsewhere = $shape->createParent();
        [$destination, [$holder]] = $shape->with(1);
        $stale = $shape->find($this->key($member))->load($shape->parentRelation());
        $shape->find($this->key($member))->move(Placement::last()->in($elsewhere), expected: null);

        $stale->move(Placement::first()->in($destination), expected: null);

        $this->assertSame($destination, $stale->getRelation($shape->parentRelation()));
        $this->assertSame($destination, $stale->orderingRelation()->parent);
        $this->assertOrder($shape, [$member->getKey(), $holder->getKey()], $destination);
        $this->assertOrder($shape, [], $elsewhere);
        $this->assertOrder($shape, [], $source);
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
    public function it_rejects_a_move_to_a_destination_on_another_connection(Shape $shape): void
    {
        config()->set('database.connections.other', config('database.connections.pgsql'));
        [$source, [$moved]] = $shape->with(1);
        [$destination] = $shape->with(1);
        $elsewhere = (clone $destination)->setConnection('other');

        $this->assertRejected($shape, LogicException::class, fn (): OrderChange => $moved->move(Placement::last()->in($elsewhere), expected: null), $source, $destination);
        $this->assertSame($source->getKey(), $moved->getAttribute($shape->foreignKey()));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_rejects_a_save_into_a_parent_it_holds_on_another_connection(Shape $shape): void
    {
        config()->set('database.connections.other', config('database.connections.pgsql'));
        [$source, [$moved]] = $shape->with(1);
        [$destination] = $shape->with(1);
        $shape->associate($moved, (clone $destination)->setConnection('other'));

        $this->assertRejected($shape, LogicException::class, fn (): bool => $moved->save(), $source, $destination);
        $this->assertSame($source->getKey(), DB::table($shape->memberTable())->where('id', $moved->getKey())->value($shape->foreignKey()));
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
        $this->assertSame($ids, $change->order);
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

            $this->assertSame([$first->getKey(), $moving->getKey(), $other->getKey()], $change->order);
            $this->assertSame([['id' => $this->key($moving), 'position' => 2, 'previousPosition' => 3]], array_map(get_object_vars(...), $change->changed));

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
        $this->assertSame([['id' => $this->key($moved), 'position' => 2, 'previousPosition' => null]], array_map(get_object_vars(...), $change->changed));
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

    #[DataProvider('pivots')]
    #[Test]
    public function it_keeps_the_rank_key_of_a_pivot_whose_customer_is_soft_deleted_out_of_new_keys(PivotShape $shape): void
    {
        $parent = $shape->createParent();
        $first = $shape->add($parent);
        $hidden = $shape->add($parent);
        Customer::query()->findOrFail($hidden->getAttribute('customer_id'))->delete();

        $customer = Customer::create();
        $shape->customers($parent)->attach($customer);
        $attached = $shape->pivotOf($parent, $customer);

        $shape->orderedRelation($parent)->reorder([$this->key($attached), $this->key($first)], expected: null);

        $this->assertSame([$attached->getKey(), $first->getKey()], $shape->members($parent));
        $this->assertSame([$attached->getKey(), $first->getKey(), $hidden->getKey()], $this->storedOrder($shape, $parent));

        $next = $first;
        $inserted = [];

        while (strlen(FractionalIndex::keyBetween($this->storedRankKey($shape, $attached), $this->storedRankKey($shape, $next))) <= OrderedRelation::MAX_KEY_LENGTH) {
            $next = $shape->add($parent, Placement::after($this->key($attached)));
            array_unshift($inserted, $next->getKey());
        }

        $longest = $shape->longestRankKey($parent);

        $member = $shape->add($parent, Placement::after($this->key($attached)));

        $this->assertLessThan($longest, $shape->longestRankKey($parent));
        $this->assertSame([$attached->getKey(), $member->getKey(), ...$inserted, $first->getKey()], $shape->members($parent));
        $this->assertSame([$attached->getKey(), $member->getKey(), ...$inserted, $first->getKey(), $hidden->getKey()], $this->storedOrder($shape, $parent));
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_counts_no_pivot_whose_customer_is_soft_deleted_as_a_member(PivotShape $shape): void
    {
        $parent = $shape->createParent();
        $first = $shape->add($parent);
        $hidden = $shape->add($parent);
        $last = $shape->add($parent);
        Customer::query()->findOrFail($hidden->getAttribute('customer_id'))->delete();
        $revision = $shape->storedRevision($parent);
        DB::enableQueryLog();

        $change = $last->move(Placement::after($this->key($first)), expected: $revision);

        $this->assertSame([], $this->writes());
        $this->assertSame([], $change->changed);
        $this->assertSame([$first->getKey(), $last->getKey()], $change->order);
        $this->assertNull($shape->find($this->key($hidden))->getAttribute('position'));
        $this->assertSame(2, $shape->find($this->key($last))->getAttribute('position'));
        $this->assertRejected($shape, InvalidAnchor::class, fn (): OrderChange => $last->move(Placement::before($this->key($hidden)), expected: null), $parent);
        $this->assertRejected($shape, InvalidAnchor::class, fn (): Model => $shape->add($parent, Placement::before($this->key($hidden))), $parent);
        $this->assertRejected($shape, MembershipMismatch::class, fn (): OrderChange => $shape->orderedRelation($parent)->reorder([$this->key($last), $this->key($hidden), $this->key($first)], expected: null), $parent);
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
        $this->assertSame(ListingRevision::ScenesOrder, Scene::query()->findOrFail($inListing->id)->orderingRelation()->revision);
        $this->assertInstanceOf(Listing::class, Scene::query()->findOrFail($inListing->id)->orderingRelation()->parent);
    }

    #[Test]
    public function it_moves_a_scene_between_parents_of_two_types(): void
    {
        $tour = Tour::create();
        $listing = Listing::create();
        $staying = $this->addScene($tour);
        $moved = $this->addScene($tour);
        $member = $this->addScene($listing);

        $change = $moved->move(Placement::first()->in($listing), expected: 2);

        $this->assertSame($listing, $change->relation->parent);
        $this->assertSame([$moved->id, $member->id], $change->order);
        $this->assertNotNull($change->source);
        $this->assertSame($tour, $change->source->relation->parent);
        $this->assertSame([$staying->id], $change->source->order);
        $this->assertSame($this->sceneRevision($tour), $tour->concurrencyRevision(TourRevision::ScenesOrder));
        $this->assertSame(4, $this->sceneRevision($tour));
        $this->assertSame($this->sceneRevision($listing), $listing->concurrencyRevision(ListingRevision::ScenesOrder));
        $this->assertSame(3, $this->sceneRevision($listing));
        $this->assertSame($listing->getMorphClass(), $moved->sceneable_type);
        $this->assertSame($listing->id, $moved->sceneable_id);
        $this->assertSame(['sceneable_type' => $listing->getMorphClass(), 'sceneable_id' => $listing->id], (array) DB::table('scenes')->where('id', $moved->id)->first(['sceneable_type', 'sceneable_id']));
        $this->assertFalse($moved->relationLoaded('sceneable'));
        $this->assertSame($listing, $moved->orderingRelation()->parent);
        $this->assertSame(1, $moved->position);
    }

    #[Test]
    public function it_clears_the_foreign_key_of_the_parent_a_member_leaves_for_a_parent_of_another_class(): void
    {
        $board = Board::create();
        $album = Album::create();
        $staying = $this->addPhoto($board);
        $moved = $this->addPhoto($board);

        $change = $moved->move(Placement::first()->in($album), expected: 1);

        $this->assertSame(['board_id' => null, 'album_id' => $album->id], (array) DB::table('photos')->where('id', $moved->id)->first(['board_id', 'album_id']));
        $this->assertNull($moved->board_id);
        $this->assertSame($album->id, $moved->album_id);
        $this->assertSame($album, $change->relation->parent);
        $this->assertSame([$moved->id], $change->order);
        $this->assertNotNull($change->source);
        $this->assertSame($board, $change->source->relation->parent);
        $this->assertSame([$staying->id], $change->source->order);
        $this->assertSame($album, $moved->orderingRelation()->parent);
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

            $moved->move(Placement::first()->in($destination), expected: 1);

            $racer->commit();

            $result = $rivals[0]->wait();

            $this->assertSame('deleted', $result->output(), $result->errorOutput());
            $this->assertSoftDeleted('assets', ['id' => $moved->id]);
            $this->assertSame(4, $this->sectionRevision($source));
            $this->assertSame(3, $this->sectionRevision($destination));
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

    #[DataProvider('shapes')]
    #[Test]
    public function it_names_the_parent_that_a_write_of_the_member_locks_first_without_a_lock(Shape $shape): void
    {
        [$parent, [$member]] = $shape->with(1);
        $loaded = $shape->find($this->key($member));
        DB::enableQueryLog();

        $parents = $loaded->parentsToLockFirst();

        $this->assertSame([[$parent::class, $parent->getKey()]], array_map(fn (Model $model): array => [$model::class, $model->getKey()], $parents));
        $this->assertSame([], array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $query): bool => preg_match('/\bfor (no key )?(update|share)\b/', $query) === 1));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_names_the_parent_that_a_save_takes_the_member_from_and_the_parent_it_moves_it_to(Shape $shape): void
    {
        [$source, [$member]] = $shape->with(1);
        [$destination] = $shape->with(1);
        $shape->associate($member, $destination);

        $parents = $member->parentsToLockFirst();

        $this->assertSame(
            [[$source::class, $source->getKey()], [$destination::class, $destination->getKey()]],
            array_map(fn (Model $model): array => [$model::class, $model->getKey()], $parents),
        );
    }

    #[Test]
    public function it_names_no_parent_for_a_member_that_no_parent_orders(): void
    {
        $this->assertSame([], (new Asset)->parentsToLockFirst());
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_names_no_parent_for_attributes_that_name_a_parent_that_does_not_exist(Shape $shape): void
    {
        [$source, [$member]] = $shape->with(1);
        $missing = $shape->createParent();
        DB::table($shape->parentTable())->where('id', $missing->getKey())->delete();
        $member->setAttribute($shape->foreignKey(), $missing->getKey());

        $parents = $member->parentsToLockFirst();

        $this->assertSame([[$source::class, $source->getKey()]], array_map(fn (Model $model): array => [$model::class, $model->getKey()], $parents));
        $this->assertRejected($shape, LogicException::class, fn (): bool => $member->save(), $source);
    }

    #[Test]
    public function it_names_each_parent_of_attributes_that_name_two_parents(): void
    {
        $board = Board::create();
        $album = Album::create();
        $photo = $this->addPhoto($board);
        $photo->album_id = $album->id;

        $this->assertSame(
            [[Board::class, $board->id], [Album::class, $album->id]],
            array_map(fn (Model $model): array => [$model::class, $model->getKey()], $photo->parentsToLockFirst()),
        );
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_names_the_parent_on_the_connection_of_the_member_for_a_parent_it_holds_on_another_connection(Shape $shape): void
    {
        config()->set('database.connections.other', config('database.connections.pgsql'));
        [$source, [$member]] = $shape->with(1);
        [$destination] = $shape->with(1);
        $shape->associate($member, (clone $destination)->setConnection('other'));

        $parents = $member->parentsToLockFirst();

        $this->assertSame(
            [[$source::class, $source->getKey(), 'pgsql'], [$destination::class, $destination->getKey(), 'pgsql']],
            array_map(fn (Model $model): array => [$model::class, $model->getKey(), $model->getConnection()->getName()], $parents),
        );
    }

    #[Test]
    public function it_finishes_a_delete_that_locks_the_parents_first_and_a_reorder_of_the_parent_without_a_deadlock(): void
    {
        $racer = $this->racer();
        [$section, [$deleted, $second, $third]] = $this->racingSectionWith(3);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('sections')->where('id', $section->id)->lockForUpdate()->first();

            $rivals[] = $this->startReorder($section, [$third->id, $deleted->id, $second->id]);
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startLockedDelete($deleted, 'parents first');
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $outputs = array_map(fn (InvokedProcess $rival): string => $rival->wait()->output(), $rivals);

            $this->assertSame(['reordered', 'deleted'], $outputs, implode(PHP_EOL, array_map(fn (InvokedProcess $rival): string => $rival->errorOutput(), $rivals)));
            $this->assertSoftDeleted('assets', ['id' => $deleted->id]);
            $this->assertOrder(new HasManyShape, [$third->id, $second->id], $section);
            $this->assertSame(6, $this->sectionRevision($section));
        } finally {
            $this->finishRace($rivals, $section);
        }
    }

    #[Test]
    public function it_deadlocks_a_delete_that_locks_the_member_before_its_parents_with_a_reorder_of_the_parent(): void
    {
        $racer = $this->racer();
        [$section, [$deleted, $second, $third]] = $this->racingSectionWith(3);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('sections')->where('id', $section->id)->lockForUpdate()->first();

            $rivals[] = $this->startReorder($section, [$third->id, $deleted->id, $second->id]);
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startLockedDelete($deleted, 'the member first');
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $outputs = array_map(fn (InvokedProcess $rival): string => $rival->wait()->output(), $rivals);

            $this->assertContains('40P01', $outputs, implode(PHP_EOL, array_map(fn (InvokedProcess $rival): string => $rival->errorOutput(), $rivals)));
        } finally {
            $this->finishRace($rivals, $section);
        }
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_fires_the_model_events_of_a_member_and_then_of_its_parent_for_a_move_within_the_parent(Shape $shape): void
    {
        [$parent, [, , $moved]] = $shape->with(3);
        $this->travel(1)->minutes();
        $member = ModelEvents::row($moved);
        $revision = $shape->storedRevision($parent);
        $events = ModelEvents::record();

        $moved->move(Placement::first(), expected: null);

        $this->assertSame([
            ...self::savedEvents($moved, $member, ModelEvents::row($moved)),
            ...self::raisedEvents($parent, $shape->column(), $revision),
        ], self::sqlOf($events->fired));
        $this->assertArrayHasKey(OrderedRelation::RANK_KEY, $events->fired[0][4]);
        $this->assertSame($revision + 1, $shape->storedRevision($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_fires_the_model_events_of_a_member_and_then_of_both_parents_for_a_move_between_parents(Shape $shape): void
    {
        [$source, [, $moved]] = $shape->with(2);
        [$destination] = $shape->with(1);
        $this->travel(1)->minutes();
        $member = ModelEvents::row($moved);
        $revisions = [$shape->storedRevision($source), $shape->storedRevision($destination)];
        $events = ModelEvents::record();

        $moved->move(Placement::last()->in($destination), expected: null);

        $this->assertSame([
            ...self::savedEvents($moved, $member, ModelEvents::row($moved)),
            ...self::raisedEvents($source, $shape->column(), $revisions[0]),
            ...self::raisedEvents($destination, $shape->column(), $revisions[1]),
        ], self::sqlOf($events->fired));
        $this->assertSame([$shape->foreignKey() => $destination->getKey()], array_intersect_key($events->fired[0][4], [$shape->foreignKey() => true]));
        $this->assertSame([$revisions[0] + 1, $revisions[1] + 1], [$shape->storedRevision($source), $shape->storedRevision($destination)]);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_fires_the_model_events_of_a_member_whose_rank_key_a_soft_delete_clears(Shape $shape): void
    {
        [, [, $deleted]] = $shape->with(3);
        $this->travel(1)->minutes();
        $rankKey = $this->storedRankKey($shape, $deleted);
        $events = ModelEvents::record();

        $deleted->delete();

        $this->assertSame(ModelEvents::saveEvents($deleted, [OrderedRelation::RANK_KEY => $rankKey], [OrderedRelation::RANK_KEY => null]), $events->of($deleted));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_the_updated_event_class_of_a_moved_member_and_its_parent(Shape $shape): void
    {
        [$parent, [, $moved]] = $shape->with(2);
        $updated = [];

        Event::listen(ModelUpdated::class, function (ModelUpdated $event) use (&$updated): void {
            $updated[] = [$event->model::class, $event->model->getKey()];
        });

        $moved->move(Placement::first(), expected: null);

        $this->assertSame([[$moved::class, $moved->getKey()], [$parent::class, $parent->getKey()]], $updated);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_advances_the_updated_at_of_a_moved_member_and_not_of_its_parents(Shape $shape): void
    {
        [$source, [, $moved]] = $shape->with(2);
        [$destination] = $shape->with(1);
        $parents = fn (): array => [ModelEvents::row($source)['updated_at'], ModelEvents::row($destination)['updated_at']];
        $before = $parents();

        $this->travel(1)->minutes();
        $moved->move(Placement::first(), expected: null);

        $this->assertSame(now()->toDateTimeString(), ModelEvents::row($moved)['updated_at']);

        $this->travel(1)->minutes();
        $moved->move(Placement::last()->in($destination), expected: null);

        $this->assertSame(now()->toDateTimeString(), ModelEvents::row($moved)['updated_at']);
        $this->assertSame(now()->toDateTimeString(), $moved->getRawOriginal('updated_at'));
        $this->assertFalse($moved->isDirty());
        $this->assertSame($before, $parents());
    }

    /**
     * @param  Closure(Model, Model): Model  $saved
     */
    #[DataProvider('listenerWrites')]
    #[Test]
    public function it_writes_what_a_listener_sets_on_a_member_or_a_parent_that_a_move_saves(Shape $shape, Closure $saved, string $event): void
    {
        [$parent, [$first, $moved]] = $shape->with(2);
        $model = $saved($parent, $moved);

        Event::listen(sprintf('eloquent.%s: %s', $event, $model::class), function (Model $model): void {
            $model->setAttribute('updated_at', '2001-02-03 04:05:06');
        });

        $moved->move(Placement::first(), expected: null);

        $this->assertSame('2001-02-03 04:05:06', ModelEvents::row($model)['updated_at']);
        $this->assertOrder($shape, [$moved->getKey(), $first->getKey()], $parent);
    }

    /**
     * @param  Closure(Shape): array{list<Model>, Closure(): mixed}  $prepare
     */
    #[DataProvider('packageWrites')]
    #[Test]
    public function it_raises_each_order_revision_that_a_write_changes_by_one_in_a_write_that_commits(Shape $shape, Closure $prepare): void
    {
        [$parents, $write] = $prepare($shape);
        $before = array_map(fn (Model $parent): int => $shape->storedRevision($parent), $parents);

        $this->runDeferredChecks($write);

        $this->assertSame(
            array_map(fn (int $revision): int => $revision + 1, $before),
            array_map(fn (Model $parent): int => $shape->storedRevision($parent), $parents),
        );
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_runs_none_of_its_own_listeners_for_a_member_that_a_move_saves(Shape $shape): void
    {
        [, [, $moved]] = $shape->with(2);
        [$destination] = $shape->with(1);
        $events = ModelEvents::record();
        DB::enableQueryLog();

        $moved->move(Placement::first(), expected: null);
        $moved->move(Placement::last()->in($destination), expected: null);

        $locks = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $query): bool => $this->locksParent($shape, $query));

        $this->assertCount(8, $events->of($moved));
        $this->assertCount(3, $locks);
    }

    #[Test]
    public function it_raises_the_order_revision_and_reports_a_leave_for_a_membership_change_that_drops_a_member(): void
    {
        [$playlist, [$first, $leaving, $third]] = $this->playlist('audio', ['audio', 'audio', 'audio']);
        $revision = $this->tracksRevision($playlist);
        $this->recordReorders();

        $leaving->kind = 'video';
        $this->runDeferredChecks(fn (): bool => $leaving->save());

        $this->assertSame($revision + 1, $this->tracksRevision($playlist));
        $this->assertNull(Track::query()->findOrFail($leaving->id)->position);
        $this->assertSame([[$playlist->id, [$first->id, $third->id], [[$leaving->id, null, 2]], $revision + 1]], $this->reorders);
    }

    #[Test]
    public function it_raises_the_order_revision_and_reports_an_enter_for_a_membership_change_that_admits_a_row(): void
    {
        [$playlist, [$first, $entering, $third]] = $this->playlist('audio', ['audio', 'video', 'audio']);
        $revision = $this->tracksRevision($playlist);
        $this->recordReorders();

        $entering->kind = 'audio';
        $this->runDeferredChecks(fn (): bool => $entering->save());

        $this->assertSame($revision + 1, $this->tracksRevision($playlist));
        $this->assertSame(2, Track::query()->findOrFail($entering->id)->position);
        $this->assertSame([[$playlist->id, [$first->id, $entering->id, $third->id], [[$entering->id, 2, null]], $revision + 1]], $this->reorders);
    }

    #[Test]
    public function it_raises_the_order_revision_without_a_report_for_a_membership_change_that_keeps_a_row_out(): void
    {
        [$playlist, [, $excluded]] = $this->playlist('audio', ['audio', 'video']);
        $revision = $this->tracksRevision($playlist);
        $this->recordReorders();

        $excluded->kind = 'podcast';
        $this->runDeferredChecks(fn (): bool => $excluded->save());

        $this->assertSame($revision + 1, $this->tracksRevision($playlist));
        $this->assertSame([], $this->reorders);
    }

    #[Test]
    public function it_reads_the_membership_of_a_row_once_before_and_once_after_its_membership_change_under_the_parent_lock(): void
    {
        [, [, $leaving]] = $this->playlist('audio', ['audio', 'audio']);
        DB::enableQueryLog();

        $leaving->update(['kind' => 'video']);

        $queries = array_column(DB::getQueryLog(), 'query');
        $lock = array_find_key($queries, fn (string $query): bool => str_contains($query, 'from "playlists"') && str_ends_with($query, 'for no key update'));
        $write = array_find_key($queries, fn (string $query): bool => str_starts_with($query, 'update "tracks" set "kind"'));
        $reads = array_keys(array_filter($queries, fn (string $query): bool => str_contains($query, 'row_number() over')));

        $this->assertIsInt($lock);
        $this->assertIsInt($write);
        $this->assertCount(2, $reads);
        $this->assertTrue($lock < $reads[0] && $reads[0] < $write && $write < $reads[1]);
    }

    #[Test]
    public function it_reads_the_membership_columns_of_a_held_parent_from_the_locked_row_for_a_membership_change(): void
    {
        [$playlist, [, $second]] = $this->playlist('audio', ['audio', 'video']);
        $track = Track::query()->with('playlist')->findOrFail($second->id);
        DB::table('playlists')->where('id', $playlist->id)->update([
            'kind' => 'video',
            'concurrency_revision' => DB::raw('"concurrency_revision" + 1'),
            'tracks_order_concurrency_revision' => DB::raw('"tracks_order_concurrency_revision" + 1'),
        ]);
        $revision = $this->tracksRevision($playlist);
        $this->recordReorders();

        $track->update(['kind' => 'audio']);

        $this->assertSame('video', $track->playlist->kind);
        $this->assertSame([[$playlist->id, [], [[$second->id, null, 1]], $revision + 1]], $this->reorders);
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

    private function tracksRevision(Playlist $playlist): int
    {
        return (int) DB::table('playlists')->where('id', $playlist->id)->value('tracks_order_concurrency_revision');
    }

    private function recordReorders(): void
    {
        Event::listen(Reordered::class, function (Reordered $event): void {
            $change = $event->change;
            $this->reorders[] = [
                $change->relation->parent->getKey(),
                $change->order,
                array_map(fn (PositionChange $changed): array => [$changed->id, $changed->position, $changed->previousPosition], $change->changed),
                $change->revision,
            ];
        });
    }

    private function storedRankKey(Shape $shape, Model $member): string
    {
        $key = DB::table($shape->memberTable())->where('id', $member->getKey())->value('rank_key');

        $this->assertIsString($key);

        return $key;
    }

    /**
     * @return list<int|string>
     */
    private function storedOrder(Shape $shape, Model $parent): array
    {
        $keys = array_filter($shape->rankKeys($parent), is_string(...));
        uasort($keys, strcmp(...));

        return array_keys($keys);
    }

    private function addVideo(Section $section): Video
    {
        $video = new Video;
        $video->section()->associate($section);
        $video->save();

        return $video;
    }

    private function videoRevision(Video $video): int
    {
        return (int) DB::table('videos')->where('id', $video->id)->value('concurrency_revision');
    }

    private function addPhoto(Board $board): Photo
    {
        $photo = new Photo;
        $photo->board()->associate($board);
        $photo->save();

        return $photo;
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

    private static function keyOf(Model $member): int|string
    {
        $key = $member->getKey();

        assert(is_int($key) || is_string($key));

        return $key;
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
        return $this->startRival('move.php', (string) $asset->id, (string) $destination->id, (string) $expected);
    }

    private function startDelete(Asset $asset): InvokedProcess
    {
        return $this->startRival('delete.php', (string) $asset->id);
    }

    private function startLockedDelete(Asset $asset, string $order): InvokedProcess
    {
        return $this->startRival('locked-delete.php', Asset::class, (string) $asset->id, $order);
    }

    /**
     * @param  list<int>  $ids
     */
    private function startReorder(Section $section, array $ids): InvokedProcess
    {
        return $this->startRival('reorder.php', (string) $section->id, implode(',', $ids));
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

        $racer->transaction(function () use ($racer, $ids): void {
            $racer->table('assets')->whereIn('section_id', $ids)->delete();
            $racer->table('sections')->whereIn('id', $ids)->delete();
        });
    }
}
