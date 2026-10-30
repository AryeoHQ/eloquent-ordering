<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Ordering\Placement;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\TestCase;

#[CoversClass(InvalidAnchor::class)]
class InvalidAnchorTest extends TestCase
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
    public function it_names_the_anchor_and_the_order_that_does_not_hold_it(Shape $shape): void
    {
        [$parent, [$member]] = $shape->with(1);
        $anchor = '0190a5b2-0000-7000-8000-000000000000';

        try {
            $member->move(Placement::after($anchor), expected: null);
            $this->fail('A move after an anchor outside the order succeeded.');
        } catch (InvalidAnchor $exception) {
            $this->assertInstanceOf(InvalidArgumentException::class, $exception);
            $this->assertSame(sprintf(
                'The anchor [%s] is not a current member of the [%s] order of [%s:%s].',
                $anchor,
                $shape->revision()->value,
                $parent->getMorphClass(),
                $parent->getKey(),
            ), $exception->getMessage());
        }
    }
}
