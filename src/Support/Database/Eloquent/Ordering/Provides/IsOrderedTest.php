<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Ordering\Exceptions\InvalidAnchor;
use Support\Database\Eloquent\Ordering\Placement;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Videos\Video;
use Tests\TestCase;

#[CoversClass(InvalidAnchor::class)]
#[CoversClass(Placement::class)]
#[CoversTrait(IsOrdered::class)]
class IsOrderedTest extends TestCase
{
    #[Test]
    public function it_places_a_new_member_last_by_default(): void
    {
        $section = Section::create();

        $first = $this->addAsset($section);
        $second = $this->addAsset($section);
        $third = $this->addAsset($section, Placement::last());

        $this->assertOrder([$first->id, $second->id, $third->id], $section);
    }

    #[Test]
    public function it_places_a_new_member_first(): void
    {
        $section = Section::create();

        $second = $this->addAsset($section);
        $first = $this->addAsset($section, Placement::first());

        $this->assertOrder([$first->id, $second->id], $section);
    }

    #[Test]
    public function it_places_a_new_member_before_an_anchor(): void
    {
        $section = Section::create();

        $first = $this->addAsset($section);
        $third = $this->addAsset($section);
        $second = $this->addAsset($section, Placement::before($third->id));

        $this->assertOrder([$first->id, $second->id, $third->id], $section);
    }

    #[Test]
    public function it_places_a_new_member_after_an_anchor(): void
    {
        $section = Section::create();

        $first = $this->addAsset($section);
        $third = $this->addAsset($section);
        $second = $this->addAsset($section, Placement::after($first->id));

        $this->assertOrder([$first->id, $second->id, $third->id], $section);
    }

    #[Test]
    public function it_rejects_an_anchor_outside_the_relation(): void
    {
        $section = Section::create();
        $member = $this->addAsset($section);
        $foreign = $this->addAsset(Section::create());

        try {
            $this->addAsset($section, Placement::before($foreign->id));
            $this->fail('A foreign anchor placed a new member.');
        } catch (InvalidAnchor) {
            $this->assertOrder([$member->id], $section);
            $this->assertSame(2, $this->storedRevision($section));
        }
    }

    #[Test]
    public function it_rejects_a_trashed_anchor(): void
    {
        $section = Section::create();
        $trashed = $this->addAsset($section);
        $trashed->delete();

        $this->expectException(InvalidAnchor::class);

        $this->addAsset($section, Placement::after($trashed->id));
    }

    #[Test]
    public function it_rejects_an_anchor_that_cannot_be_a_key(): void
    {
        $section = Section::create();
        $this->addAsset($section);

        $this->expectException(InvalidAnchor::class);

        $this->addAsset($section, Placement::after('first'));
    }

    #[Test]
    public function it_numbers_every_member_from_one_whatever_the_caller_can_see(): void
    {
        $section = Section::create();

        $hidden = $this->addAsset($section, attributes: ['visible' => false]);
        $visible = $this->addAsset($section);

        $this->assertSame(2, Asset::query()->findOrFail($visible->id)->position);
        $this->assertOrder([$hidden->id, $visible->id], $section);
    }

    #[Test]
    public function it_keeps_positions_dense_when_a_member_is_deleted(): void
    {
        $section = Section::create();

        $first = $this->addAsset($section);
        $second = $this->addAsset($section);
        $third = $this->addAsset($section);

        $second->delete();

        $this->assertNull($second->position);
        $this->assertOrder([$first->id, $third->id], $section);
    }

    #[Test]
    public function it_takes_a_soft_deleted_member_out_of_the_relation(): void
    {
        $section = Section::create();
        $asset = $this->addAsset($section);
        $other = $this->addAsset($section);

        $asset->delete();

        $this->assertTrue($asset->trashed());
        $this->assertNull($asset->rank_key);
        $this->assertNull(DB::table('assets')->where('id', $asset->id)->value('rank_key'));
        $this->assertSame([$other->id], $section->orderedRelation(SectionRevision::AssetsOrder)->query()->pluck('id')->all());
        $this->assertOrder([$other->id], $section);
    }

    #[Test]
    public function it_increments_the_order_revision_for_a_create_and_a_delete_only(): void
    {
        $section = Section::create();

        $asset = $this->addAsset($section);
        $this->assertSame(2, $this->storedRevision($section));

        $asset->update(['title' => 'Dusk']);
        $this->assertSame(2, $this->storedRevision($section));

        $asset->forceDelete();
        $this->assertSame(3, $this->storedRevision($section));
    }

    #[Test]
    public function it_writes_the_order_revision_into_the_parent_it_holds_after_a_create(): void
    {
        $section = Section::create();

        $asset = $this->addAsset($section);

        $this->assertSame(2, $section->concurrencyRevision(SectionRevision::AssetsOrder));
        $this->assertFalse($section->isDirty());
        $this->assertSame($section, $asset->orderedRelation()->parent);
    }

    #[Test]
    public function it_writes_the_order_revision_into_the_parent_it_holds_after_a_soft_delete(): void
    {
        $section = Section::create();
        $asset = $this->addAsset($section);

        $asset->delete();

        $this->assertGreaterThan(2, $section->concurrencyRevision(SectionRevision::AssetsOrder));
        $this->assertSame($this->storedRevision($section), $section->concurrencyRevision(SectionRevision::AssetsOrder));
        $this->assertFalse($section->isDirty());
    }

    #[Test]
    public function it_writes_the_order_revision_into_the_parent_it_holds_after_a_force_delete(): void
    {
        $section = Section::create();
        $asset = $this->addAsset($section);

        $asset->forceDelete();

        $this->assertSame(3, $section->concurrencyRevision(SectionRevision::AssetsOrder));
        $this->assertFalse($section->isDirty());
    }

    #[Test]
    public function it_writes_the_order_revision_into_the_parent_it_resolves(): void
    {
        $section = Section::create();

        $asset = Asset::create(['section_id' => $section->id]);

        $this->assertNotSame($section, $asset->orderedRelation()->parent);
        $this->assertSame(2, $asset->orderedRelation()->parent->concurrencyRevision(SectionRevision::AssetsOrder));
    }

    #[Test]
    public function it_resolves_the_relation_that_orders_its_class_when_the_parent_orders_two_classes(): void
    {
        $section = Section::create();

        $asset = $this->addAsset($section);
        $this->assertSame(2, $this->storedRevision($section, SectionRevision::AssetsOrder));
        $this->assertSame(1, $this->storedRevision($section, SectionRevision::VideosOrder));

        $video = new Video;
        $video->section()->associate($section);
        $video->save();
        $this->assertSame(2, $this->storedRevision($section, SectionRevision::AssetsOrder));
        $this->assertSame(2, $this->storedRevision($section, SectionRevision::VideosOrder));

        $this->assertSame(SectionRevision::AssetsOrder, $asset->orderedRelation()->revision);
        $this->assertSame(SectionRevision::VideosOrder, $video->orderedRelation()->revision);
        $this->assertSame(1, $asset->position);
        $this->assertSame(1, $video->position);
        $this->assertSame([$video->id], $section->orderedRelation(SectionRevision::VideosOrder)->query()->pluck('id')->all());
    }

    #[Test]
    public function it_places_a_new_member_in_the_relation_that_its_foreign_key_names(): void
    {
        $cached = Section::create();
        $named = Section::create();
        $asset = (new Asset(['section_id' => $named->id]))->setRelation('section', $cached);

        $asset->save();

        $this->assertSame(1, $this->storedRevision($cached));
        $this->assertSame(2, $this->storedRevision($named));
        $this->assertOrder([$asset->id], $named);
    }

    #[Test]
    public function it_locks_the_parent_before_it_inserts(): void
    {
        $section = Section::create();
        DB::enableQueryLog();

        $this->addAsset($section);

        $this->assertParentLockedBefore('insert into "assets"');
    }

    #[Test]
    public function it_locks_the_parent_before_it_soft_deletes(): void
    {
        $asset = $this->addAsset(Section::create());
        DB::enableQueryLog();

        $asset->delete();

        $this->assertParentLockedBefore('update "assets"');
    }

    #[Test]
    public function it_locks_the_parent_before_it_force_deletes(): void
    {
        $asset = $this->addAsset(Section::create());
        DB::enableQueryLog();

        $asset->forceDelete();

        $this->assertParentLockedBefore('delete from "assets"');
    }

    #[Test]
    public function it_refuses_a_new_member_without_a_parent(): void
    {
        $this->expectException(LogicException::class);

        (new Asset)->save();
    }

    #[Test]
    public function it_writes_nothing_when_a_later_listener_halts_a_create(): void
    {
        $section = Section::create();
        $member = $this->addAsset($section);

        Asset::creating(fn (): bool => false);

        $asset = new Asset(['section_id' => $section->id]);

        $this->assertFalse($asset->save());
        $this->assertFalse($asset->exists);
        $this->assertSame(2, $this->storedRevision($section));
        $this->assertOrder([$member->id], $section);
    }

    #[Test]
    public function it_writes_nothing_when_a_later_listener_halts_a_delete(): void
    {
        $section = Section::create();
        $asset = $this->addAsset($section);

        Asset::deleting(fn (): bool => false);

        $this->assertFalse($asset->delete());
        $this->assertSame(2, $this->storedRevision($section));
        $this->assertOrder([$asset->id], $section);
    }

    #[Test]
    public function it_keeps_the_rank_key_out_of_its_serialized_form(): void
    {
        $asset = $this->addAsset(Section::create());

        $this->assertArrayNotHasKey('rank_key', $asset->toArray());
    }

    #[Test]
    public function it_has_no_position_until_it_is_saved(): void
    {
        $this->assertNull((new Asset)->position);
    }

    #[Test]
    public function it_refuses_a_placement_for_an_existing_member(): void
    {
        $asset = $this->addAsset(Section::create());

        $this->expectException(LogicException::class);

        $asset->placeAt(Placement::first());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function addAsset(Section $section, null|Placement $placement = null, array $attributes = []): Asset
    {
        $asset = new Asset($attributes);
        $asset->section()->associate($section);

        if ($placement !== null) {
            $asset->placeAt($placement);
        }

        $asset->save();

        return $asset;
    }

    /**
     * @param  list<int>  $expected
     */
    private function assertOrder(array $expected, Section $section): void
    {
        $positions = Asset::query()
            ->withoutGlobalScope(Asset::VISIBLE_SCOPE)
            ->where('section_id', $section->id)
            ->get()
            ->mapWithKeys(fn (Asset $asset): array => [$asset->id => $asset->position])
            ->sort();

        $this->assertSame(array_map(fn (int $index): int => $index + 1, array_keys($expected)), $positions->values()->all());
        $this->assertSame($expected, $positions->keys()->all());
    }

    private function assertParentLockedBefore(string $write): void
    {
        $statements = array_column(DB::getQueryLog(), 'query');

        $lock = array_find_key($statements, fn (string $statement): bool => str_contains($statement, 'from "sections"') && str_ends_with($statement, 'for no key update'));
        $written = array_find_key($statements, fn (string $statement): bool => str_starts_with($statement, $write));

        $this->assertIsInt($lock, 'The parent row was never locked.');
        $this->assertIsInt($written, 'The item was never written.');
        $this->assertLessThan($written, $lock);
    }

    private function storedRevision(Section $section, SectionRevision $revision = SectionRevision::AssetsOrder): int
    {
        return (int) DB::table('sections')->where('id', $section->id)->value($revision->value.'_concurrency_revision');
    }
}
