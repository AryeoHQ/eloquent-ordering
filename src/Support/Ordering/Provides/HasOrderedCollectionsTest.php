<?php

declare(strict_types=1);

namespace Support\Ordering\Provides;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Support\Sections\Section;
use Tests\TestCase;

#[CoversTrait(HasOrderedCollections::class)]
class HasOrderedCollectionsTest extends TestCase
{
    #[Test]
    public function it_returns_the_named_ordered_collection(): void
    {
        $section = Section::factory()->create();

        $collection = $section->orderedCollection('assets');

        $this->assertSame($section, $collection->parent);
        $this->assertSame('assets', $collection->name);
        $this->assertSame(0, $collection->version());
    }

    #[Test]
    public function it_rejects_a_name_that_is_not_an_ordered_has_many_relation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Section::factory()->create()->orderedCollection('getTable');
    }

    #[Test]
    public function it_calls_no_method_that_does_not_declare_a_has_many_relation(): void
    {
        $section = Section::factory()->create();

        try {
            $section->orderedCollection('delete');
            $this->fail('A method that declares no HasMany relation was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(Section::query()->whereKey($section->id)->exists());
        }
    }
}
