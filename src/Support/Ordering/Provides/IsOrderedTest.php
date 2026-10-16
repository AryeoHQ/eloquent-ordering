<?php

declare(strict_types=1);

namespace Support\Ordering\Provides;

use LogicException;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Support\Ordering\Exceptions\InvalidAnchor;
use Support\Ordering\Placement;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Assets\OrdersAssets;
use Tests\Fixtures\Support\Sections\Section;
use Tests\TestCase;

#[CoversTrait(IsOrdered::class)]
class IsOrderedTest extends TestCase
{
    use OrdersAssets;

    #[Test]
    public function it_places_a_new_member_last_by_default(): void
    {
        $section = Section::factory()->create();

        $first = $this->addAsset($section);
        $second = $this->addAsset($section);
        $third = $this->addAsset($section, Placement::last());

        $this->assertOrder([$first->id, $second->id, $third->id], $section);
    }

    #[Test]
    public function it_places_a_new_member_first(): void
    {
        $section = Section::factory()->create();

        $second = $this->addAsset($section);
        $first = $this->addAsset($section, Placement::first());

        $this->assertOrder([$first->id, $second->id], $section);
    }

    #[Test]
    public function it_places_a_new_member_before_an_anchor(): void
    {
        $section = Section::factory()->create();

        $first = $this->addAsset($section);
        $third = $this->addAsset($section);
        $second = $this->addAsset($section, Placement::before($third->id));

        $this->assertOrder([$first->id, $second->id, $third->id], $section);
    }

    #[Test]
    public function it_places_a_new_member_after_an_anchor(): void
    {
        $section = Section::factory()->create();

        $first = $this->addAsset($section);
        $third = $this->addAsset($section);
        $second = $this->addAsset($section, Placement::after($first->id));

        $this->assertOrder([$first->id, $second->id, $third->id], $section);
    }

    #[Test]
    public function it_rejects_an_anchor_outside_the_collection(): void
    {
        $section = Section::factory()->create();
        $member = $this->addAsset($section);
        $foreign = $this->addAsset(Section::factory()->create());

        try {
            $this->addAsset($section, Placement::before($foreign->id));
            $this->fail('A foreign anchor placed a new member.');
        } catch (InvalidAnchor) {
            $this->assertOrder([$member->id], $section);
            $this->assertSame(1, $section->orderedCollection('assets')->version());
        }
    }

    #[Test]
    public function it_counts_every_member_whatever_the_caller_can_see(): void
    {
        $section = Section::factory()->create();

        $hidden = $this->addAsset($section, attributes: ['visible' => false]);
        $visible = $this->addAsset($section);

        $this->assertSame(2, Asset::query()->findOrFail($visible->id)->position);
        $this->assertOrder([$hidden->id, $visible->id], $section);
    }

    #[Test]
    public function it_keeps_positions_dense_when_a_member_leaves(): void
    {
        $section = Section::factory()->create();

        $first = $this->addAsset($section);
        $second = $this->addAsset($section);
        $third = $this->addAsset($section);

        $second->delete();

        $this->assertOrder([$first->id, $third->id], $section);
        $this->assertSame(4, $section->orderedCollection('assets')->version());
    }

    #[Test]
    public function it_versions_the_collection_once_for_each_member_that_joins_or_leaves(): void
    {
        $section = Section::factory()->create();
        $collection = $section->orderedCollection('assets');

        $asset = $this->addAsset($section);
        $this->assertSame(1, $collection->version());

        $asset->update(['title' => 'Dusk']);
        $this->assertSame(1, $collection->version());

        $asset->delete();
        $this->assertSame(2, $collection->version());
    }

    #[Test]
    public function it_keeps_keys_short_and_positions_dense_through_one_thousand_inserts_at_one_point(): void
    {
        $section = Section::factory()->create();
        $first = $this->addAsset($section);
        $last = $this->addAsset($section);

        $inserted = [];
        $longest = 0;
        $rebalances = 0;

        for ($i = 0; $i < 1000; $i++) {
            $asset = $this->addAsset($section, Placement::after($first->id));
            $length = strlen($asset->rank_key);

            if ($length < $longest) {
                $rebalances++;
            }

            $longest = $length;
            array_unshift($inserted, $asset->id);
        }

        $this->assertGreaterThan(0, $rebalances);
        $this->assertLessThanOrEqual(64, max(array_map(strlen(...), $this->rankKeys($section))));
        $this->assertSame(1002, $section->orderedCollection('assets')->version());
        $this->assertOrder([$first->id, ...$inserted, $last->id], $section);
    }

    #[Test]
    public function it_keeps_the_rank_key_out_of_its_serialized_form(): void
    {
        $asset = $this->addAsset(Section::factory()->create());

        $this->assertArrayNotHasKey('rank_key', $asset->toArray());
    }

    #[Test]
    public function it_has_no_position_until_it_is_saved(): void
    {
        $this->assertNull(Asset::factory()->make()->position);
    }

    #[Test]
    public function it_refuses_a_placement_for_an_existing_member(): void
    {
        $asset = $this->addAsset(Section::factory()->create());

        $this->expectException(LogicException::class);

        $asset->placeAt(Placement::first());
    }
}
