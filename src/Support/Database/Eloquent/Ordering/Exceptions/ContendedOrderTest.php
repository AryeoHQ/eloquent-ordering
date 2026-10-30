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
            $this->assertStringContainsString(sprintf('[%s:%s]', $moved::class, $moved->getKey()), $exception->getMessage());
        }

        $this->assertSame(10, $reads);
        $this->assertSame($before, $this->stored($shape, $moved, $source, $other));
    }

    /**
     * @return list<mixed>
     */
    private function stored(Shape $shape, Model $member, Model $source, Model $other): array
    {
        return [
            (array) DB::table($shape->memberTable())->where('id', $member->getKey())->first(),
            $shape->storedRevision($source),
            $shape->storedRevision($other),
            $shape->rankKeys($source),
            $shape->rankKeys($other),
        ];
    }
}
