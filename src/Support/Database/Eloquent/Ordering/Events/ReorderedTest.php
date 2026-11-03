<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Events;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Placement;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Shapes\Reparenting;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\TestCase;

#[CoversClass(Reordered::class)]
class ReorderedTest extends TestCase
{
    private int $baseline = 0;

    private int $updated = 0;

    /** @var list<Reordered> */
    private array $dispatched = [];

    /** @var list<int> */
    private array $levels = [];

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
    public static function writes(): iterable
    {
        return Shape::crossed([
            'a create' => [
                function (Shape $shape): Closure {
                    $parent = $shape->createParent();

                    return fn (): mixed => $shape->add($parent);
                },
                1,
            ],
            'a move within a parent' => [
                function (Shape $shape): Closure {
                    [, $members] = $shape->with(3);

                    return fn (): mixed => $members[0]->move(Placement::last(), null);
                },
                1,
            ],
            'a move between parents' => [
                function (Shape $shape): Closure {
                    [, $members] = $shape->with(2);
                    $destination = $shape->createParent();

                    return fn (): mixed => $members[0]->move(Placement::last()->in($destination), null);
                },
                2,
            ],
            'a save that changes the parent' => [
                function (Shape $shape): Closure {
                    [, [, $moved]] = $shape->with(2);
                    [$destination] = $shape->with(1);

                    return function () use ($shape, $moved, $destination): mixed {
                        $shape->associate($moved, $destination);

                        return $moved->save();
                    };
                },
                2,
            ],
            'a reorder' => [
                function (Shape $shape): Closure {
                    [$parent, $members] = $shape->with(3);
                    $ids = array_map(fn (Model $member): mixed => $member->getKey(), array_reverse($members));

                    return fn (): mixed => $shape->arrange($parent, $ids, null);
                },
                1,
            ],
            'a soft delete' => [
                function (Shape $shape): Closure {
                    [, $members] = $shape->with(3);

                    return fn (): mixed => $members[1]->delete();
                },
                1,
            ],
            'a force delete' => [
                function (Shape $shape): Closure {
                    [, $members] = $shape->with(3);

                    return fn (): mixed => $members[1]->forceDelete();
                },
                1,
            ],
            'a restore' => [
                function (Shape $shape): Closure {
                    [, $members] = $shape->with(3);
                    $members[1]->delete();
                    assert(method_exists($members[1], 'restore'));

                    return fn (): mixed => $members[1]->restore();
                },
                1,
            ],
        ]);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function batchWrites(): iterable
    {
        return Shape::crossed([
            'a move in' => [
                function (Shape $shape): Closure {
                    [, $members] = $shape->with(3);
                    [$destination] = $shape->with(1);

                    return fn (): mixed => $shape->relation($destination)->moveIn([$members[2], $members[0]], expected: null);
                },
                2,
            ],
            'a create in order' => [
                function (Shape $shape): Closure {
                    [$parent] = $shape->with(1);

                    return fn (): mixed => $shape->relation($parent)->createInOrder([['visible' => true], ['visible' => true]]);
                },
                1,
            ],
        ]);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function restores(): iterable
    {
        return Shape::crossed([
            'after a model soft delete' => [function (Shape $shape, Model $member): Model {
                $member->delete();

                return $member;
            }],
            'after a bulk soft delete' => [function (Shape $shape, Model $member): Model {
                $key = $member->getKey();
                assert(is_int($key) || is_string($key));
                $shape->memberClass()::query()->whereKey($key)->delete();

                return $shape->find($key);
            }],
        ]);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function quietWrites(): iterable
    {
        return Shape::crossed([
            'a move to where it is' => [fn (Shape $shape, Model&OrdersRelations $parent, array $members): mixed => $members[1]->move(Placement::after($members[0]->getKey()), null)],
            'a move of the first member to the front' => [fn (Shape $shape, Model&OrdersRelations $parent, array $members): mixed => $members[0]->move(Placement::first(), null)],
            'a move into the parent it is in' => [fn (Shape $shape, Model&OrdersRelations $parent, array $members): mixed => $members[1]->move(Placement::after($members[0]->getKey())->in($parent), null)],
            'a reorder to the current order' => [fn (Shape $shape, Model&OrdersRelations $parent, array $members): mixed => $shape->arrange($parent, array_map(fn (Model $member): mixed => $member->getKey(), $members), null)],
            'a save that changes nothing' => [fn (Shape $shape, Model&OrdersRelations $parent, array $members): mixed => $members[1]->save()],
            'a move in of members where they are' => [fn (Shape $shape, Model&OrdersRelations $parent, array $members): mixed => $shape->relation($parent)->moveIn([$members[1], $members[2]], expected: null, placement: Placement::after($members[0]->getKey()))],
            'a move in of nothing' => [fn (Shape $shape, Model&OrdersRelations $parent, array $members): mixed => $shape->relation($parent)->moveIn([], expected: null)],
        ]);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function counts(): iterable
    {
        return Shape::crossed([
            'one member' => [1],
            'five members' => [5],
            'twenty members' => [20],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseline = DB::transactionLevel();

        Event::listen(Reordered::class, function (Reordered $event): void {
            $this->dispatched[] = $event;
            $this->levels[] = DB::transactionLevel();
        });
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_one_event_for_a_create(Shape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);
        $this->forget();

        $member = $shape->add($parent, Placement::first());

        $this->assertCount(1, $this->dispatched);

        $change = $this->dispatched[0]->change;

        $this->assertSame($parent, $change->parent);
        $this->assertSame([$member->getKey(), $first->getKey(), $second->getKey()], $change->order);
        $this->assertSame([['id' => $this->key($member), 'position' => 1, 'previousPosition' => null]], array_map(get_object_vars(...), $change->changed));
        $this->assertSame($shape->digest($parent), $change->digest);
        $this->assertNull($change->source);
        $this->assertSame([$this->baseline], $this->levels);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_dispatches_one_event_for_an_attach(PivotShape $shape): void
    {
        $parent = $shape->createParent();
        $first = Customer::create();
        $second = Customer::create();
        $shape->customers($parent)->attach($first);
        $this->forget();

        $shape->customers($parent)->attach($second);

        $pivot = $shape->pivotOf($parent, $second);

        $this->assertCount(1, $this->dispatched);

        $change = $this->dispatched[0]->change;

        $this->assertSame($parent, $change->parent);
        $this->assertSame($pivot->getKey(), $change->order[1]);
        $this->assertSame([['id' => $this->key($pivot), 'position' => 2, 'previousPosition' => null]], array_map(get_object_vars(...), $change->changed));
        $this->assertSame($shape->digest($parent), $change->digest);
        $this->assertSame([$this->baseline], $this->levels);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_one_event_for_a_move_within_a_parent(Shape $shape): void
    {
        [, [, , $third]] = $shape->with(3);
        $this->forget();

        $change = $third->move(Placement::first(), null);

        $this->assertCount(1, $this->dispatched);
        $this->assertSame($change, $this->dispatched[0]->change);
        $this->assertSame([$this->baseline], $this->levels);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_one_event_for_each_parent_of_a_move_between_parents(Shape $shape): void
    {
        [$source, [, $moved]] = $shape->with(2);
        [$destination] = $shape->with(1);
        $this->forget();

        $change = $moved->move(Placement::last()->in($destination), null);

        $this->assertNotNull($change->source);
        $this->assertCount(2, $this->dispatched);
        $this->assertSame($change->source, $this->dispatched[0]->change);
        $this->assertSame($change, $this->dispatched[1]->change);
        $this->assertSame($source, $this->dispatched[0]->change->parent);
        $this->assertSame($destination, $this->dispatched[1]->change->parent);
        $this->assertSame([$this->baseline, $this->baseline], $this->levels);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_one_event_for_each_parent_of_a_save_that_changes_the_parent(Shape $shape): void
    {
        [$source, [$first, $moved, $third]] = $shape->with(3);
        [$destination, [$member]] = $shape->with(1);
        $this->forget();

        $shape->associate($moved, $destination);
        $moved->save();

        $this->assertCount(2, $this->dispatched);

        [$left, $entered] = [$this->dispatched[0]->change, $this->dispatched[1]->change];

        $this->assertSame($source, $left->parent);
        $this->assertSame([$first->getKey(), $third->getKey()], $left->order);
        $this->assertSame([['id' => $this->key($moved), 'position' => null, 'previousPosition' => 2]], array_map(get_object_vars(...), $left->changed));
        $this->assertSame($shape->digest($source), $left->digest);
        $this->assertNull($left->source);

        $this->assertSame($destination, $entered->parent);
        $this->assertSame([$member->getKey(), $moved->getKey()], $entered->order);
        $this->assertSame([['id' => $this->key($moved), 'position' => 2, 'previousPosition' => null]], array_map(get_object_vars(...), $entered->changed));
        $this->assertSame($shape->digest($destination), $entered->digest);
        $this->assertSame($left, $entered->source);
        $this->assertSame([$this->baseline, $this->baseline], $this->levels);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_one_event_for_a_save_that_moves_a_member_without_a_rank_key_to_another_parent(Shape $shape): void
    {
        [, [$moved]] = $shape->with(2);
        [$destination, [$member]] = $shape->with(1);
        DB::table($shape->memberTable())->where('id', $moved->getKey())->update([OrderedRelation::RANK_KEY => null]);
        $keyless = $shape->find($this->key($moved));
        $this->forget();

        $shape->associate($keyless, $destination);
        $keyless->save();

        $this->assertCount(1, $this->dispatched);

        $change = $this->dispatched[0]->change;

        $this->assertSame($destination, $change->parent);
        $this->assertSame([$member->getKey(), $moved->getKey()], $change->order);
        $this->assertSame([['id' => $this->key($moved), 'position' => 2, 'previousPosition' => null]], array_map(get_object_vars(...), $change->changed));
        $this->assertSame($shape->digest($destination), $change->digest);
        $this->assertNull($change->source);
        $this->assertSame([$this->baseline], $this->levels);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_dispatches_no_event_for_a_save_that_moves_a_pivot_the_relation_excludes(PivotShape $shape): void
    {
        [, [$excluded]] = $shape->with(1);
        [$destination] = $shape->with(1);
        Customer::query()->findOrFail($excluded->getAttribute('customer_id'))->delete();
        $this->forget();

        $shape->associate($excluded, $destination);
        $excluded->save();

        $this->assertSame([], $this->dispatched);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_dispatches_no_event_for_an_attach_the_relation_excludes(PivotShape $shape): void
    {
        [$parent] = $shape->with(1);
        $customer = Customer::create();
        $customer->delete();
        $this->forget();

        $shape->customers($parent)->attach($customer);

        $this->assertNotNull(DB::table($shape->memberTable())->where('customer_id', $customer->id)->value(OrderedRelation::RANK_KEY));
        $this->assertSame([], $this->dispatched);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_dispatches_no_event_for_a_restore_the_relation_excludes(PivotShape $shape): void
    {
        [, [, $excluded]] = $shape->with(2);
        $excluded->delete();
        Customer::query()->findOrFail($excluded->getAttribute('customer_id'))->delete();
        assert(method_exists($excluded, 'restore'));
        $this->forget();

        $this->assertTrue($excluded->restore());

        $this->assertNotNull(DB::table($shape->memberTable())->where('id', $excluded->getKey())->value(OrderedRelation::RANK_KEY));
        $this->assertSame([], $this->dispatched);
    }

    /**
     * @param  Closure(): array{Model&OrdersRelations&Revisioned, Model&Ordered, list<Model&Ordered>, array<string, mixed>}  $reparent
     */
    #[DataProvider('departures')]
    #[Test]
    public function it_dispatches_one_event_for_a_save_that_takes_a_member_out_of_every_order(Closure $reparent): void
    {
        [$parent, $leaving, $staying, $attributes] = $reparent();
        $this->forget();

        $leaving->update($attributes);

        $this->assertCount(1, $this->dispatched);

        $change = $this->dispatched[0]->change;

        $this->assertSame($parent, $change->parent);
        $this->assertSame(array_map(fn (Model $member): mixed => $member->getKey(), $staying), $change->order);
        $this->assertSame([['id' => $this->key($leaving), 'position' => null, 'previousPosition' => 1]], array_map(get_object_vars(...), $change->changed));
        $this->assertSame($change->parent->concurrencyRevision($change->revision), $change->digest);
        $this->assertNull($change->source);
        $this->assertSame([$this->baseline], $this->levels);
    }

    /**
     * @param  Closure(): array{Model&OrdersRelations&Revisioned, Model&Ordered, list<Model&Ordered>, array<string, mixed>}  $reparent
     */
    #[DataProvider('arrivals')]
    #[Test]
    public function it_dispatches_one_event_for_a_save_that_gives_a_member_a_parent(Closure $reparent): void
    {
        [$parent, $arriving, $members, $attributes] = $reparent();
        $this->forget();

        $arriving->update($attributes);

        $this->assertCount(1, $this->dispatched);

        $change = $this->dispatched[0]->change;

        $this->assertTrue($parent->is($change->parent));
        $this->assertSame(array_map(fn (Model $member): mixed => $member->getKey(), [...$members, $arriving]), $change->order);
        $this->assertSame([['id' => $this->key($arriving), 'position' => count($members) + 1, 'previousPosition' => null]], array_map(get_object_vars(...), $change->changed));
        $this->assertSame($change->parent->concurrencyRevision($change->revision), $change->digest);
        $this->assertNull($change->source);
        $this->assertSame([$this->baseline], $this->levels);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_one_event_for_each_parent_of_a_stale_restore_that_changes_the_parent(Shape $shape): void
    {
        [$source, [$restored]] = $shape->with(1);
        [$destination, [$member]] = $shape->with(1);
        $restored->delete();
        $stale = $shape->find($this->key($restored));
        assert(method_exists($restored, 'restore') && method_exists($stale, 'restore'));
        $restored->restore();
        $this->forget();

        $shape->associate($stale, $destination);
        $stale->restore();

        $this->assertCount(2, $this->dispatched);

        [$left, $entered] = [$this->dispatched[0]->change, $this->dispatched[1]->change];

        $this->assertTrue($source->is($left->parent));
        $this->assertSame([], $left->order);
        $this->assertSame([['id' => $this->key($stale), 'position' => null, 'previousPosition' => 1]], array_map(get_object_vars(...), $left->changed));
        $this->assertSame($shape->digest($source), $left->digest);

        $this->assertSame($destination, $entered->parent);
        $this->assertSame([$member->getKey(), $stale->getKey()], $entered->order);
        $this->assertSame([['id' => $this->key($stale), 'position' => 2, 'previousPosition' => null]], array_map(get_object_vars(...), $entered->changed));
        $this->assertSame($shape->digest($destination), $entered->digest);
        $this->assertSame($left, $entered->source);
        $this->assertSame([$this->baseline, $this->baseline], $this->levels);
    }

    #[DataProvider('counts')]
    #[Test]
    public function it_dispatches_one_event_for_each_collection_a_move_in_changes(Shape $shape, int $count): void
    {
        [$sources, $moved] = $this->sourcesOf($shape, $count);
        [$destination, [$kept]] = $shape->with(1);
        $this->forget();

        $change = $shape->relation($destination)->moveIn($moved, expected: null);

        $this->assertCount(count($sources) + 1, $this->dispatched);
        $this->assertSame($change, $this->dispatched[count($sources)]->change);
        $this->assertSame([$kept->getKey(), ...array_map(fn (Model $member): mixed => $member->getKey(), $moved)], $change->order);
        $this->assertSame(
            array_map(fn (Model $member, int $index): array => ['id' => $this->key($member), 'position' => $index + 2, 'previousPosition' => null], $moved, array_keys($moved)),
            array_map(get_object_vars(...), $change->changed),
        );
        $this->assertSame(count($sources) === 1 ? $this->dispatched[0]->change : null, $change->source);

        foreach ($sources as [$source, $left]) {
            $event = array_find(array_slice($this->dispatched, 0, count($sources)), fn (Reordered $event): bool => $event->change->parent === $source);

            $this->assertNotNull($event);
            $this->assertCount(1, $event->change->order);
            $this->assertSame(
                array_map(fn (Model $member, int $index): array => ['id' => $this->key($member), 'position' => null, 'previousPosition' => $index + 1], $left, array_keys($left)),
                array_map(get_object_vars(...), $event->change->changed),
            );
        }

        $this->assertSame(array_fill(0, count($sources) + 1, $this->baseline), $this->levels);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_one_event_for_a_move_in_inside_one_parent(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $this->forget();

        $change = $shape->relation($parent)->moveIn([$third, $second], expected: null, placement: Placement::first());

        $this->assertCount(1, $this->dispatched);
        $this->assertSame($change, $this->dispatched[0]->change);
        $this->assertSame([$third->getKey(), $second->getKey(), $first->getKey()], $change->order);
        $this->assertSame(
            [['id' => $this->key($third), 'position' => 1, 'previousPosition' => 3]],
            array_map(get_object_vars(...), $change->changed),
        );
    }

    #[DataProvider('counts')]
    #[Test]
    public function it_dispatches_one_event_for_a_create_in_order(Shape $shape, int $count): void
    {
        [$parent, [$first]] = $shape->with(1);
        $this->forget();

        $shape->relation($parent)->createInOrder(array_fill(0, $count, ['visible' => true]));

        $this->assertCount(1, $this->dispatched);

        $change = $this->dispatched[0]->change;

        $this->assertSame($parent, $change->parent);
        $this->assertCount($count + 1, $change->order);
        $this->assertSame($first->getKey(), $change->order[0]);
        $this->assertSame(
            array_map(fn (int|string $id, int $index): array => ['id' => $id, 'position' => $index + 2, 'previousPosition' => null], array_slice($change->order, 1), range(0, $count - 1)),
            array_map(get_object_vars(...), $change->changed),
        );
        $this->assertSame($shape->digest($parent), $change->digest);
        $this->assertNull($change->source);
        $this->assertSame([$this->baseline], $this->levels);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_reports_only_the_moved_members_for_a_move_in_that_rebalances(Shape $shape): void
    {
        [$parent, [$first, $second, $third, $fourth]] = $shape->with(4);
        $shape->crowd($first, $second);
        $this->forget();

        $change = $shape->relation($parent)->moveIn([$fourth, $third], expected: null, placement: Placement::after($this->key($first)));

        $this->assertLessThan(OrderedRelation::MAX_KEY_LENGTH, $shape->longestRankKey($parent));
        $this->assertCount(1, $this->dispatched);
        $this->assertSame($change, $this->dispatched[0]->change);
        $this->assertSame([$first->getKey(), $fourth->getKey(), $third->getKey(), $second->getKey()], $change->order);
        $this->assertSame(
            [['id' => $this->key($fourth), 'position' => 2, 'previousPosition' => 4]],
            array_map(get_object_vars(...), $change->changed),
        );
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_one_event_for_each_collection_of_a_move_in_that_rebalances_with_an_entering_member(Shape $shape): void
    {
        [$source, [$entering]] = $shape->with(1);
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $shape->crowd($first, $second);
        $this->forget();

        $change = $shape->relation($parent)->moveIn([$entering, $third], expected: null, placement: Placement::after($this->key($first)));

        $this->assertLessThan(OrderedRelation::MAX_KEY_LENGTH, $shape->longestRankKey($parent));
        $this->assertCount(2, $this->dispatched);
        $this->assertSame($source, $this->dispatched[0]->change->parent);
        $this->assertSame(
            [['id' => $this->key($entering), 'position' => null, 'previousPosition' => 1]],
            array_map(get_object_vars(...), $this->dispatched[0]->change->changed),
        );
        $this->assertSame($change, $this->dispatched[1]->change);
        $this->assertSame($this->dispatched[0]->change, $change->source);
        $this->assertSame([$first->getKey(), $entering->getKey(), $third->getKey(), $second->getKey()], $change->order);
        $this->assertSame($change->order, $shape->members($parent));
        $this->assertSame(
            [['id' => $this->key($entering), 'position' => 2, 'previousPosition' => null]],
            array_map(get_object_vars(...), $change->changed),
        );
        $this->assertSame([], $shape->members($source));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_one_event_for_a_reorder(Shape $shape): void
    {
        [$parent, $members] = $shape->with(3);
        $ids = array_map(fn (Model $member): mixed => $member->getKey(), array_reverse($members));
        $this->forget();

        $change = $shape->arrange($parent, $ids, null);

        $this->assertCount(1, $this->dispatched);
        $this->assertSame($change, $this->dispatched[0]->change);
        $this->assertSame([$this->baseline], $this->levels);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_one_event_for_a_soft_delete(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $this->forget();

        $second->delete();

        $this->assertCount(1, $this->dispatched);

        $change = $this->dispatched[0]->change;

        $this->assertSame($parent, $change->parent);
        $this->assertSame([$first->getKey(), $third->getKey()], $change->order);
        $this->assertSame([['id' => $this->key($second), 'position' => null, 'previousPosition' => 2]], array_map(get_object_vars(...), $change->changed));
        $this->assertSame($shape->digest($parent), $change->digest);
        $this->assertNull($change->source);
        $this->assertSame([$this->baseline], $this->levels);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_one_event_for_a_force_delete(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $this->forget();

        $second->forceDelete();

        $this->assertCount(1, $this->dispatched);

        $change = $this->dispatched[0]->change;

        $this->assertSame($parent, $change->parent);
        $this->assertSame([$first->getKey(), $third->getKey()], $change->order);
        $this->assertSame([['id' => $this->key($second), 'position' => null, 'previousPosition' => 2]], array_map(get_object_vars(...), $change->changed));
        $this->assertSame($shape->digest($parent), $change->digest);
        $this->assertSame([$this->baseline], $this->levels);
    }

    /**
     * @param  Closure(Shape, Model&Ordered): (Model&Ordered)  $delete
     */
    #[DataProvider('restores')]
    #[Test]
    public function it_dispatches_one_event_for_a_restore(Shape $shape, Closure $delete): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $restored = $delete($shape, $first);
        assert(method_exists($restored, 'restore'));
        $this->forget();

        $restored->restore();

        $this->assertCount(1, $this->dispatched);

        $change = $this->dispatched[0]->change;

        $this->assertTrue($parent->is($change->parent));
        $this->assertSame([$second->getKey(), $third->getKey(), $first->getKey()], $change->order);
        $this->assertSame([['id' => $this->key($first), 'position' => 3, 'previousPosition' => null]], array_map(get_object_vars(...), $change->changed));
        $this->assertSame($shape->digest($parent), $change->digest);
        $this->assertNull($change->source);
        $this->assertSame([$this->baseline], $this->levels);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_no_event_for_a_stale_restore_of_a_member_already_restored(Shape $shape): void
    {
        [, [$first, $second]] = $shape->with(3);
        $first->delete();
        $stale = $shape->find($this->key($first));
        $restored = $shape->find($this->key($first));
        assert(method_exists($stale, 'restore') && method_exists($restored, 'restore'));
        $restored->restore();
        $second->move(Placement::last(), null);
        $this->forget();

        $stale->restore();

        $this->assertSame([], $this->dispatched);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_no_event_for_a_stale_restore_of_a_force_deleted_member(Shape $shape): void
    {
        [, [$first]] = $shape->with(2);
        $first->delete();
        $stale = $shape->find($this->key($first));
        $shape->find($this->key($first))->forceDelete();
        assert(method_exists($stale, 'restore'));
        $this->forget();

        $stale->restore();

        $this->assertSame([], $this->dispatched);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_dispatches_one_event_for_a_detach(PivotShape $shape): void
    {
        $parent = $shape->createParent();
        [$first, $second, $third] = [Customer::create(), Customer::create(), Customer::create()];
        $shape->customers($parent)->attach([$first->id, $second->id, $third->id]);
        [$firstPivot, $secondPivot, $thirdPivot] = array_map(fn (Customer $customer): Model => $shape->pivotOf($parent, $customer), [$first, $second, $third]);
        $this->forget();

        $shape->customers($parent)->detach($second);

        $this->assertCount(1, $this->dispatched);

        $change = $this->dispatched[0]->change;

        $this->assertSame($parent, $change->parent);
        $this->assertSame([$firstPivot->getKey(), $thirdPivot->getKey()], $change->order);
        $this->assertSame([['id' => $this->key($secondPivot), 'position' => null, 'previousPosition' => 2]], array_map(get_object_vars(...), $change->changed));
        $this->assertSame($shape->digest($parent), $change->digest);
        $this->assertSame([$this->baseline], $this->levels);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_no_event_for_the_delete_of_a_member_with_no_place(Shape $shape): void
    {
        [, [, $second]] = $shape->with(3);
        $second->delete();
        $this->forget();

        $second->forceDelete();

        $this->assertSame([], $this->dispatched);
    }

    /**
     * @param  Closure(Shape, Model&OrdersRelations, list<Model&Ordered>): mixed  $write
     */
    #[DataProvider('quietWrites')]
    #[Test]
    public function it_dispatches_no_event_for_a_write_that_keeps_the_order(Shape $shape, Closure $write): void
    {
        [$parent, $members] = $shape->with(3);
        $this->forget();

        $write($shape, $parent, $members);

        $this->assertSame([], $this->dispatched);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_no_event_for_a_stale_move(Shape $shape): void
    {
        [$parent, [, $second]] = $shape->with(2);
        $stale = $shape->digest($parent);
        $shape->add($parent);
        $this->forget();

        try {
            $second->move(Placement::first(), expected: $stale);

            $this->fail('The stale move was accepted.');
        } catch (StaleConcurrencyRevision) {
        }

        $this->assertSame([], $this->dispatched);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_no_event_for_a_create_that_a_listener_halts(Shape $shape): void
    {
        $parent = $shape->createParent();
        $shape->add($parent);
        $shape->memberClass()::creating(fn (): bool => false);
        $halted = $shape->newMember($parent);
        $this->forget();

        $this->assertFalse($halted->save());
        $this->assertSame([], $this->dispatched);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_no_event_for_a_delete_that_a_listener_halts(Shape $shape): void
    {
        [, [$member]] = $shape->with(1);
        $shape->memberClass()::deleting(fn (): bool => false);
        $this->forget();

        $member->delete();

        $this->assertSame([], $this->dispatched);
    }

    /**
     * @param  Closure(Shape): (Closure(): mixed)  $prepare
     */
    #[DataProvider('writes')]
    #[DataProvider('batchWrites')]
    #[Test]
    public function it_dispatches_after_the_outer_transaction_commits(Shape $shape, Closure $prepare, int $count): void
    {
        $write = $prepare($shape);
        $this->forget();

        DB::transaction(function () use ($write): void {
            $write();

            $this->assertSame([], $this->dispatched);
        });

        $this->assertCount($count, $this->dispatched);
        $this->assertSame(array_fill(0, $count, $this->baseline), $this->levels);
    }

    /**
     * @param  Closure(Shape): (Closure(): mixed)  $prepare
     */
    #[DataProvider('writes')]
    #[DataProvider('batchWrites')]
    #[Test]
    public function it_dispatches_no_event_for_a_write_that_rolls_back(Shape $shape, Closure $prepare, int $count): void
    {
        $write = $prepare($shape);
        $this->forget();

        try {
            DB::transaction(function () use ($write): never {
                $write();

                throw new RuntimeException('Roll back.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->dispatched);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_fires_an_updated_event_for_each_member_a_move_or_a_reorder_rewrites(Shape $shape): void
    {
        [$parent, $members] = $shape->with(4);
        $ids = array_map(fn (Model $member): mixed => $member->getKey(), array_reverse($members));
        $this->countUpdatedEvents($shape);
        $this->forget();
        $keys = [$shape->rankKeys($parent)];

        $members[3]->move(Placement::first(), null);
        $keys[] = $shape->rankKeys($parent);
        $shape->arrange($parent, $ids, null);
        $keys[] = $shape->rankKeys($parent);

        $this->assertCount(2, $this->dispatched);
        $this->assertSame(count(array_diff_assoc($keys[1], $keys[0])) + count(array_diff_assoc($keys[2], $keys[1])), $this->updated);
        $this->assertGreaterThan(1, $this->updated);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_reports_only_the_moved_member_for_a_move_that_rebalances(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $shape->crowd($first, $second);
        $this->countUpdatedEvents($shape);
        $this->forget();

        $change = $third->move(Placement::after($this->key($first)), null);

        $this->assertLessThan(OrderedRelation::MAX_KEY_LENGTH, $shape->longestRankKey($parent));
        $this->assertCount(1, $this->dispatched);
        $this->assertSame($change, $this->dispatched[0]->change);
        $this->assertSame([['id' => $this->key($third), 'position' => 2, 'previousPosition' => 3]], array_map(get_object_vars(...), $change->changed));
        $this->assertSame(3, $this->updated);
    }

    /**
     * @return array{list<array{Model, list<Model&Ordered>}>, list<Model&Ordered>}
     */
    private function sourcesOf(Shape $shape, int $count): array
    {
        $sources = [];
        $moved = [];

        foreach ([intdiv($count + 1, 2), intdiv($count, 2)] as $leaving) {
            if ($leaving > 0) {
                [$source, $members] = $shape->with($leaving + 1);
                $sources[] = [$source, array_slice($members, 0, $leaving)];
                array_push($moved, ...array_slice($members, 0, $leaving));
            }
        }

        return [$sources, $moved];
    }

    private function forget(): void
    {
        $this->dispatched = [];
        $this->levels = [];
    }

    private function key(Model $member): int|string
    {
        $key = $member->getKey();

        $this->assertTrue(is_int($key) || is_string($key));

        return $key;
    }

    private function countUpdatedEvents(Shape $shape): void
    {
        Event::listen('eloquent.updated: '.$shape->memberClass(), function (): void {
            $this->updated++;
        });
    }
}
