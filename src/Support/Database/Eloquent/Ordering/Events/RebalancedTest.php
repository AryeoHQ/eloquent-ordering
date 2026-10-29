<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Placement;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\TestCase;

#[CoversClass(Rebalanced::class)]
class RebalancedTest extends TestCase
{
    /** @var list<Rebalanced|Reordered> */
    private array $dispatched = [];

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

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen([Rebalanced::class, Reordered::class], function (Rebalanced|Reordered $event): void {
            $this->dispatched[] = $event;
        });
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_carries_the_relation_the_revision_after_the_write_and_every_rewritten_member(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);
        $shape->crowd($first, $second);
        DB::table($shape->memberTable())->where('id', $third->getKey())->update([OrderedRelation::RANK_KEY => 'a5']);
        $this->forget();

        $change = $third->move(Placement::after($this->key($first)), expected: null);

        $this->assertCount(2, $this->dispatched);

        $rebalanced = $this->dispatched[0];

        $this->assertInstanceOf(Rebalanced::class, $rebalanced);
        $this->assertSame($change->relation, $rebalanced->relation);
        $this->assertSame($change->revision, $rebalanced->revision);
        $this->assertSame($shape->storedRevision($parent), $rebalanced->revision);
        $this->assertSame([$first->getKey(), $second->getKey(), $third->getKey()], $rebalanced->ids);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_before_the_reordered_event_of_the_relation_it_rebalanced(Shape $shape): void
    {
        [, [$moved]] = $shape->with(1);
        [$destination, [$first, $second]] = $shape->with(2);
        $shape->crowd($first, $second);
        $this->forget();

        $change = $moved->move(Placement::after($this->key($first))->in($destination), expected: null);

        $this->assertSame([Reordered::class, Rebalanced::class, Reordered::class], array_map(fn (object $event): string => $event::class, $this->dispatched));

        [$left, $rebalanced, $entered] = $this->dispatched;

        $this->assertInstanceOf(Reordered::class, $left);
        $this->assertInstanceOf(Rebalanced::class, $rebalanced);
        $this->assertInstanceOf(Reordered::class, $entered);
        $this->assertSame($change->source, $left->change);
        $this->assertSame($change->relation, $rebalanced->relation);
        $this->assertSame([$first->getKey(), $second->getKey()], $rebalanced->ids);
        $this->assertSame($change, $entered->change);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_dispatches_for_a_create_that_dispatches_no_reordered_event(PivotShape $shape): void
    {
        [$parent, [$first, $second]] = $shape->with(2);
        $shape->crowd($first, $second);
        $customer = Customer::create();
        $customer->delete();
        $class = $shape->memberClass();
        $excluded = new $class(['customer_id' => $customer->id]);
        $shape->associate($excluded, $parent);
        $this->forget();

        $excluded->placeAt(Placement::after($this->key($first)))->save();

        $this->assertCount(1, $this->dispatched);

        $rebalanced = $this->dispatched[0];

        $this->assertInstanceOf(Rebalanced::class, $rebalanced);
        $this->assertTrue($parent->is($rebalanced->relation->parent));
        $this->assertSame($shape->revision(), $rebalanced->relation->revision);
        $this->assertSame($shape->storedRevision($parent), $rebalanced->revision);
        $this->assertSame([$first->getKey(), $second->getKey()], $rebalanced->ids);
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_dispatches_nothing_for_a_rebalance_that_rolls_back(Shape $shape): void
    {
        [, [$first, $second, $third]] = $shape->with(3);
        $shape->crowd($first, $second);
        $this->forget();

        try {
            DB::transaction(function () use ($first, $third): never {
                $third->move(Placement::after($this->key($first)), expected: null);

                throw new RuntimeException('Roll back.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->dispatched);

        $third->move(Placement::after($this->key($first)), expected: null);

        $this->assertSame([Rebalanced::class, Reordered::class], array_map(fn (object $event): string => $event::class, $this->dispatched));
    }

    private function forget(): void
    {
        $this->dispatched = [];
    }

    private function key(Model $member): int|string
    {
        $key = $member->getKey();

        $this->assertTrue(is_int($key) || is_string($key));

        return $key;
    }
}
