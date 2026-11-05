<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Testing;

use LogicException;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Ordering\Placement;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Sections\Section;
use Tests\TestCase;

#[CoversTrait(PlacesOrderedItems::class)]
class PlacesOrderedItemsTest extends TestCase
{
    #[Test]
    public function it_creates_an_item_at_each_placement(): void
    {
        $section = Section::query()->create();

        $middle = Asset::factory()->for($section)->create();
        $last = Asset::factory()->for($section)->placedAt(Placement::last())->create();
        $first = Asset::factory()->for($section)->placedAt(Placement::first())->create();
        $before = Asset::factory()->for($section)->placedAt(Placement::before($middle->id))->create();
        $after = Asset::factory()->for($section)->placedAt(Placement::after($middle->id))->create();

        $this->assertSame(
            [$first->id, $before->id, $middle->id, $after->id, $last->id],
            $this->order($section),
        );
    }

    #[Test]
    public function it_places_a_made_item_when_it_is_saved(): void
    {
        $section = Section::query()->create();
        $existing = Asset::factory()->for($section)->create();

        $made = Asset::factory()->for($section)->placedAt(Placement::first())->make();
        $made->save();

        $this->assertSame([$made->id, $existing->id], $this->order($section));
    }

    #[Test]
    public function it_places_each_item_of_a_count_in_turn(): void
    {
        $section = Section::query()->create();
        $anchor = Asset::factory()->for($section)->create();

        $made = Asset::factory()->count(2)->for($section)->placedAt(Placement::after($anchor->id))->create();

        $this->assertSame([$anchor->id, $made[1]->id, $made[0]->id], $this->order($section));
    }

    #[Test]
    public function it_refuses_a_placement_that_names_a_parent(): void
    {
        $section = Section::query()->create();

        $this->expectException(LogicException::class);

        Asset::factory()->for($section)->placedAt(Placement::first()->in($section))->make();
    }

    /**
     * @return list<int>
     */
    private function order(Section $section): array
    {
        return $section->assets()->reorder('assets.rank_key')->orderBy('assets.id')->pluck('assets.id')->all();
    }
}
