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
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Placement;
use Support\Database\Eloquent\Ordering\PositionChange;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Shapes\PivotShape;
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
            'a reorder' => [
                function (Shape $shape): Closure {
                    [$parent, $members] = $shape->with(3);
                    $ids = array_map(fn (Model $member): mixed => $member->getKey(), array_reverse($members));

                    return fn (): mixed => $shape->orderedRelation($parent)->reorder($ids, null);
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
            'a reorder to the current order' => [fn (Shape $shape, Model&OrdersRelations $parent, array $members): mixed => $shape->orderedRelation($parent)->reorder(array_map(fn (Model $member): mixed => $member->getKey(), $members), null)],
            'a save that changes nothing' => [fn (Shape $shape, Model&OrdersRelations $parent, array $members): mixed => $members[1]->save()],
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

        $this->assertSame($parent, $change->relation->parent);
        $this->assertSame([$member->getKey(), $first->getKey(), $second->getKey()], $change->order);
        $this->assertEquals([new PositionChange($this->key($member), position: 1, previousPosition: null)], $change->changed);
        $this->assertSame($shape->storedRevision($parent), $change->revision);
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

        $this->assertSame($parent, $change->relation->parent);
        $this->assertSame($pivot->getKey(), $change->order[1]);
        $this->assertEquals([new PositionChange($this->key($pivot), position: 2, previousPosition: null)], $change->changed);
        $this->assertSame($shape->storedRevision($parent), $change->revision);
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
        $this->assertSame($source, $this->dispatched[0]->change->relation->parent);
        $this->assertSame($destination, $this->dispatched[1]->change->relation->parent);
        $this->assertSame([$this->baseline, $this->baseline], $this->levels);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_one_event_for_a_reorder(Shape $shape): void
    {
        [$parent, $members] = $shape->with(3);
        $ids = array_map(fn (Model $member): mixed => $member->getKey(), array_reverse($members));
        $this->forget();

        $change = $shape->orderedRelation($parent)->reorder($ids, null);

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

        $this->assertSame($parent, $change->relation->parent);
        $this->assertSame([$first->getKey(), $third->getKey()], $change->order);
        $this->assertEquals([new PositionChange($this->key($second), position: null, previousPosition: 2)], $change->changed);
        $this->assertSame($shape->storedRevision($parent), $change->revision);
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

        $this->assertSame($parent, $change->relation->parent);
        $this->assertSame([$first->getKey(), $third->getKey()], $change->order);
        $this->assertEquals([new PositionChange($this->key($second), position: null, previousPosition: 2)], $change->changed);
        $this->assertSame($shape->storedRevision($parent), $change->revision);
        $this->assertSame([$this->baseline], $this->levels);
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

        $this->assertSame($parent, $change->relation->parent);
        $this->assertSame([$firstPivot->getKey(), $thirdPivot->getKey()], $change->order);
        $this->assertEquals([new PositionChange($this->key($secondPivot), position: null, previousPosition: 2)], $change->changed);
        $this->assertSame($shape->storedRevision($parent), $change->revision);
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
        [, [, $second]] = $shape->with(2);
        $this->forget();

        try {
            $second->move(Placement::first(), expected: 1);

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
    public function it_fires_no_updated_event_for_a_move_or_a_reorder(Shape $shape): void
    {
        [$parent, $members] = $shape->with(4);
        $ids = array_map(fn (Model $member): mixed => $member->getKey(), array_reverse($members));
        $this->countUpdatedEvents($shape);
        $this->forget();

        $members[3]->move(Placement::first(), null);
        $shape->orderedRelation($parent)->reorder($ids, null);

        $this->assertCount(2, $this->dispatched);
        $this->assertSame(0, $this->updated);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_fires_no_updated_event_for_a_rebalance(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $this->countUpdatedEvents($shape);
        $this->forget();
        $longest = 0;
        $rebalanced = false;
        $moves = 0;

        for ($attempt = 0; ! $rebalanced && $attempt < 1000; $attempt++) {
            $moving = $attempt % 2 === 0 ? $third : $second;
            $moving->move(Placement::after($first->getKey()), null);
            $moves++;

            $length = strlen($this->rankKey($moving));
            $rebalanced = $length < $longest;
            $longest = $length;
        }

        $this->assertTrue($rebalanced);
        $this->assertLessThanOrEqual(OrderedRelation::MAX_KEY_LENGTH, $shape->longestRankKey($parent));
        $this->assertCount($moves, $this->dispatched);
        $this->assertSame(0, $this->updated);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_fires_an_updated_event_for_an_eloquent_write_of_the_rank_key(Shape $shape): void
    {
        [, [$member]] = $shape->with(1);
        $this->countUpdatedEvents($shape);

        $member->setAttribute(OrderedRelation::RANK_KEY, 'b0')->save();

        $this->assertSame(1, $this->updated);
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

    private function rankKey(Model $member): string
    {
        $key = $member->getAttribute(OrderedRelation::RANK_KEY);

        $this->assertIsString($key);

        return $key;
    }

    private function countUpdatedEvents(Shape $shape): void
    {
        Event::listen('eloquent.updated: '.$shape->memberClass(), function (): void {
            $this->updated++;
        });
    }
}
