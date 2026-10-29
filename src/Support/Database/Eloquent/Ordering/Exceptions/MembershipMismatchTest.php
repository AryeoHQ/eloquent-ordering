<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\TestCase;

#[CoversClass(MembershipMismatch::class)]
class MembershipMismatchTest extends TestCase
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
    public function it_names_the_order_whose_members_a_reorder_does_not_list(Shape $shape): void
    {
        [$parent, [$first]] = $shape->with(2);
        $key = $first->getKey();
        $this->assertTrue(is_int($key) || is_string($key));

        try {
            $shape->orderedRelation($parent)->reorder([$key], expected: null);
            $this->fail('A reorder that omits a member succeeded.');
        } catch (MembershipMismatch $exception) {
            $this->assertInstanceOf(InvalidArgumentException::class, $exception);
            $this->assertSame(sprintf(
                'The ids must list each current member of the [%s] order of [%s:%s] exactly once.',
                $shape->revision()->value,
                $parent->getMorphClass(),
                $parent->getKey(),
            ), $exception->getMessage());
        }
    }
}
