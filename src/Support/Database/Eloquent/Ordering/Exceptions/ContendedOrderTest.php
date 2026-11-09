<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Support\Database\Eloquent\Ordering\Placement;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\TestCase;

#[CoversClass(ContendedOrder::class)]
class ContendedOrderTest extends TestCase
{
    /**
     * @return iterable<string, array{Shape}>
     */
    public static function shapes(): iterable
    {
        return Shape::all();
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_gives_up_after_five_attempts_when_the_member_changes_parent_before_each_lock(Shape $shape): void
    {
        [$source, [, $moved]] = $shape->with(2);
        $other = $shape->createParent();
        $before = $this->stored($shape, $moved, $source, $other);
        $reads = 0;

        DB::listen(function (QueryExecuted $query) use (&$reads, $shape, $moved, $source, $other): void {
            if (! str_starts_with($query->sql, sprintf('select "%s"', $shape->foreignKey())) || ! str_contains($query->sql, sprintf('from "%s"', $shape->memberTable()))) {
                return;
            }

            throw_if(++$reads > 10, RuntimeException::class, 'The member was read past five attempts.');

            $row = DB::table($shape->memberTable())->where('id', $moved->getKey())->first();
            $this->assertIsObject($row);

            DB::table($shape->memberTable())->where('id', $moved->getKey())->update([
                $shape->foreignKey() => $row->{$shape->foreignKey()} === $source->getKey() ? $other->getKey() : $source->getKey(),
            ]);
        });

        try {
            $moved->move(Placement::first(), expected: null);
            $this->fail('A member that changed parent before each lock was moved.');
        } catch (ContendedOrder $exception) {
            $this->assertSame(sprintf('A membership changed during each of 5 attempts to lock the order of [%s:%s].', $moved::class, $moved->getKey()), $exception->getMessage());
        }

        $this->assertSame(10, $reads);
        $this->assertSame($before, $this->stored($shape, $moved, $source, $other));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_gives_up_a_move_in_after_five_attempts_when_an_item_changes_parent_before_each_lock(Shape $shape): void
    {
        [$source, [, $moved]] = $shape->with(2);
        $other = $shape->createParent();
        [$destination] = $shape->with(1);
        $before = [$this->stored($shape, $moved, $source, $other), $shape->digest($destination), $shape->rankKeys($destination)];
        $table = $shape->memberTable();
        $reads = 0;

        DB::listen(function (QueryExecuted $query) use (&$reads, $shape, $moved, $source, $other, $table): void {
            if (! str_starts_with($query->sql, sprintf('select "%s", ', $moved->getKeyName())) || ! str_contains($query->sql, sprintf('from "%s" where "%s"."%s" in', $table, $table, $moved->getKeyName()))) {
                return;
            }

            throw_if(++$reads > 10, RuntimeException::class, 'The item was read past five attempts.');

            $row = DB::table($table)->where('id', $moved->getKey())->first();
            $this->assertIsObject($row);

            DB::table($table)->where('id', $moved->getKey())->update([
                $shape->foreignKey() => $row->{$shape->foreignKey()} === $source->getKey() ? $other->getKey() : $source->getKey(),
            ]);
        });

        try {
            $shape->relation($destination)->moveIn([$moved], expected: null);
            $this->fail('An item that changed parent before each lock was moved in.');
        } catch (ContendedOrder $exception) {
            $this->assertSame(sprintf('A membership changed during each of 5 attempts to lock the order of [%s:%s].', $destination::class, $destination->getKey()), $exception->getMessage());
        }

        $this->assertSame(10, $reads);
        $this->assertSame($before, [$this->stored($shape, $moved, $source, $other), $shape->digest($destination), $shape->rankKeys($destination)]);
    }

    /**
     * @return list<mixed>
     */
    private function stored(Shape $shape, Model $member, Model $source, Model $other): array
    {
        return [
            (array) DB::table($shape->memberTable())->where('id', $member->getKey())->first(),
            $shape->digest($source),
            $shape->digest($other),
            $shape->rankKeys($source),
            $shape->rankKeys($other),
        ];
    }
}
