<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\TestCase;

#[CoversClass(Placement::class)]
class PlacementTest extends TestCase
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
    public function it_places_a_new_member_where_each_constructor_names(Shape $shape): void
    {
        $parent = $shape->createParent();
        $middle = $shape->add($parent);
        $last = $shape->add($parent, Placement::last());
        $first = $shape->add($parent, Placement::first());
        $before = $shape->add($parent, Placement::before($this->key($middle)));
        $after = $shape->add($parent, Placement::after($this->key($middle)));

        $this->assertSame($this->keys($first, $before, $middle, $after, $last), $shape->members($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_moves_a_member_to_the_place_each_constructor_names(Shape $shape): void
    {
        [$parent, [$first, $second, $third]] = $shape->with(3);

        $third->move(Placement::first(), expected: null);
        $this->assertSame($this->keys($third, $first, $second), $shape->members($parent));

        $third->move(Placement::last(), expected: null);
        $this->assertSame($this->keys($first, $second, $third), $shape->members($parent));

        $first->move(Placement::after($this->key($second)), expected: null);
        $this->assertSame($this->keys($second, $first, $third), $shape->members($parent));

        $third->move(Placement::before($this->key($first)), expected: null);
        $this->assertSame($this->keys($second, $third, $first), $shape->members($parent));
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_moves_a_member_into_the_parent_that_in_names(Shape $shape): void
    {
        [$source, [$moved, $staying]] = $shape->with(2);
        [$destination, [$held]] = $shape->with(1);

        $moved->move(Placement::before($this->key($held))->in($destination), expected: null);

        $this->assertSame($this->keys($moved, $held), $shape->members($destination));
        $this->assertSame($this->keys($staying), $shape->members($source));
    }

    /**
     * @return list<int|string>
     */
    private function keys(Model ...$members): array
    {
        return array_values(array_map($this->key(...), $members));
    }

    private function key(Model $member): int|string
    {
        $key = $member->getKey();

        $this->assertTrue(is_int($key) || is_string($key));

        return $key;
    }
}
