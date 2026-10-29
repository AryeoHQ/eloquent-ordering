<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Ordering\Events\Rebalanced;
use Support\Database\Eloquent\Ordering\Events\Reordered;
use Support\Database\Eloquent\Ordering\OrderWrite;
use Support\Database\Eloquent\Ordering\Placement;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\TestCase;

#[CoversClass(OrderWrite::class)]
#[CoversClass(WriteHalted::class)]
class WriteHaltedTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function parentHalts(): iterable
    {
        yield from Shape::crossed([
            'a create' => [static function (Shape $shape): array {
                [$parent] = $shape->with(1);
                $member = $shape->newMember($parent);

                return [$member, [$parent], fn (): bool => $member->save()];
            }],
            'a move within a parent' => [static function (Shape $shape): array {
                [$parent, [, $moved]] = $shape->with(2);

                return [$moved, [$parent], fn (): mixed => $moved->move(Placement::first(), expected: null)];
            }],
            'a move that rebalances' => [static function (Shape $shape): array {
                [$parent, [$first, $second, $moved]] = $shape->with(3);
                $shape->crowd($first, $second);

                return [$moved, [$parent], fn (): mixed => $moved->move(Placement::after(self::keyOf($first)), expected: null)];
            }],
            'a move between parents' => [static function (Shape $shape): array {
                [$source, [, $moved]] = $shape->with(2);
                [$destination] = $shape->with(1);

                return [$moved, [$source, $destination], fn (): mixed => $moved->move(Placement::last()->in($destination), expected: null)];
            }],
            'a save that changes the parent' => [static function (Shape $shape): array {
                [$source, [, $moved]] = $shape->with(2);
                [$destination] = $shape->with(1);
                $shape->associate($moved, $destination);

                return [$moved, [$source, $destination], fn (): bool => $moved->save()];
            }],
            'a reorder' => [static function (Shape $shape): array {
                [$parent, [$first, $second]] = $shape->with(2);

                return [$parent, [$parent], fn (): mixed => $shape->orderedRelation($parent)->reorder([self::keyOf($second), self::keyOf($first)], expected: null)];
            }],
            'a soft delete' => [static function (Shape $shape): array {
                [$parent, [, $member]] = $shape->with(2);

                return [$member, [$parent], fn (): mixed => $member->delete()];
            }],
            'a force delete' => [static function (Shape $shape): array {
                [$parent, [, $member]] = $shape->with(2);
                assert(method_exists($member, 'forceDelete'));

                return [$member, [$parent], fn (): mixed => $member->forceDelete()];
            }],
            'a restore' => [static function (Shape $shape): array {
                [$parent, [, $member]] = $shape->with(2);
                $member->delete();
                assert(method_exists($member, 'restore'));

                return [$member, [$parent], fn (): mixed => $member->restore(), ['deleted_at' => null]];
            }],
        ]);

        foreach (PivotShape::pivots() as $name => [$shape]) {
            yield "{$name}, an attach" => [$shape, static function (Shape $shape): array {
                assert($shape instanceof PivotShape);
                [$parent] = $shape->with(1);

                return [$parent, [$parent], function () use ($shape, $parent): void {
                    $shape->customers($parent)->attach(Customer::create());
                }];
            }];

            yield "{$name}, a detach" => [$shape, static function (Shape $shape): array {
                assert($shape instanceof PivotShape);
                [$parent, [, $member]] = $shape->with(2);
                $customer = $member->getAttribute('customer_id');

                return [$parent, [$parent], fn (): int => $shape->customers($parent)->detach($customer)];
            }];

            yield "{$name}, a create that rebalances an order that excludes it" => [$shape, static function (Shape $shape): array {
                assert($shape instanceof PivotShape);
                [$parent, [$first, $second]] = $shape->with(2);
                $shape->crowd($first, $second);
                $customer = Customer::create();
                $customer->delete();
                $class = $shape->memberClass();
                $member = new $class(['customer_id' => $customer->id]);
                $shape->associate($member, $parent);
                $member->placeAt(Placement::after(self::keyOf($first)));

                return [$member, [$parent], fn (): bool => $member->save()];
            }];
        }
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function memberHalts(): iterable
    {
        yield from Shape::crossed([
            'a move within a parent' => [static function (Shape $shape): array {
                [$parent, [, $moved]] = $shape->with(2);

                return [$moved, $moved, [$parent], fn (): mixed => $moved->move(Placement::first(), expected: null)];
            }],
            'a move that rebalances' => [static function (Shape $shape): array {
                [$parent, [$first, $second, $moved]] = $shape->with(3);
                $shape->crowd($first, $second);

                return [$moved, $first, [$parent], fn (): mixed => $moved->move(Placement::after(self::keyOf($first)), expected: null)];
            }],
            'a move between parents' => [static function (Shape $shape): array {
                [$source, [, $moved]] = $shape->with(2);
                [$destination] = $shape->with(1);

                return [$moved, $moved, [$source, $destination], fn (): mixed => $moved->move(Placement::last()->in($destination), expected: null)];
            }],
            'a reorder' => [static function (Shape $shape): array {
                [$parent, [$first, $second]] = $shape->with(2);

                return [$parent, $second, [$parent], fn (): mixed => $shape->orderedRelation($parent)->reorder([self::keyOf($second), self::keyOf($first)], expected: null)];
            }],
            'a soft delete' => [static function (Shape $shape): array {
                [$parent, [, $member]] = $shape->with(2);

                return [$member, $member, [$parent], fn (): mixed => $member->delete()];
            }],
        ]);

        foreach (PivotShape::pivots() as $name => [$shape]) {
            yield "{$name}, a detach" => [$shape, static function (Shape $shape): array {
                assert($shape instanceof PivotShape);
                [$parent, [, $member]] = $shape->with(2);
                $customer = $member->getAttribute('customer_id');

                return [$parent, $member, [$parent], fn (): int => $shape->customers($parent)->detach($customer)];
            }];
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen([Rebalanced::class, Reordered::class], function (object $event): void {
            $this->dispatched[] = $event;
        });
    }

    /**
     * @param  Closure(Shape): array{0: Model, 1: list<Model>, 2: Closure(): mixed, 3?: array<string, mixed>}  $prepare
     */
    #[DataProvider('parentHalts')]
    #[Test]
    public function it_rolls_back_a_write_whose_parent_replay_a_listener_halts(Shape $shape, Closure $prepare): void
    {
        $prepared = $prepare($shape);
        [$caller, $parents, $write] = $prepared;

        Event::listen('eloquent.updating: '.$parents[0]::class, fn (): bool => false);

        $this->assertHalted($shape, $caller, $parents, $write, sprintf('A [updating] listener of [%s:%s]', $parents[0]::class, $parents[0]->getKey()), $prepared[3] ?? []);
    }

    /**
     * @param  Closure(Shape): array{Model, Model, list<Model>, Closure(): mixed}  $prepare
     */
    #[DataProvider('memberHalts')]
    #[Test]
    public function it_rolls_back_a_write_whose_member_replay_a_listener_halts(Shape $shape, Closure $prepare): void
    {
        [$caller, $halting, $parents, $write] = $prepare($shape);

        Event::listen('eloquent.saving: '.$shape->memberClass(), fn (): bool => false);

        $this->assertHalted($shape, $caller, $parents, $write, sprintf('A [saving] listener of [%s:%s]', $halting::class, $halting->getKey()));
    }

    private function forget(): void
    {
        $this->dispatched = [];
    }

    private static function keyOf(Model $member): int|string
    {
        $key = $member->getKey();

        assert(is_int($key) || is_string($key));

        return $key;
    }

    /**
     * @param  list<Model>  $parents
     * @param  Closure(): mixed  $write
     * @param  array<string, mixed>  $setBeforeTheWrite
     */
    private function assertHalted(Shape $shape, Model $caller, array $parents, Closure $write, string $message, array $setBeforeTheWrite = []): void
    {
        $stored = fn (): array => [
            DB::table($shape->memberTable())->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            array_map(fn (Model $parent): array => (array) DB::table($parent->getTable())->where('id', $parent->getKey())->first(), $parents),
        ];
        $state = fn (): array => [
            $caller->exists,
            $caller->getAttributes(),
            $caller->getRawOriginal(),
            array_map(fn (mixed $related): mixed => is_object($related) ? spl_object_id($related) : $related, $caller->getRelations()),
            array_map(fn (Model $parent): array => $parent->getAttributes(), $parents),
        ];
        [$before, $instances] = [$stored(), $state()];
        $instances[1] = [...$instances[1], ...$setBeforeTheWrite];
        $this->forget();

        try {
            $write();
            $this->fail('A halted replay let the write through.');
        } catch (WriteHalted $exception) {
            $this->assertStringStartsWith($message, $exception->getMessage());
        }

        $this->assertSame($before, $stored());
        $this->assertSame($instances, $state());
        $this->assertSame([], $this->dispatched);
    }
}
