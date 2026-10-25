<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Exceptions\InvalidAnchor;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Support\Database\Eloquent\Ordering\OrderChange;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Placement;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Videos\Video;
use Tests\TestCase;
use Throwable;

#[CoversClass(InvalidAnchor::class)]
#[CoversClass(Placement::class)]
#[CoversTrait(IsOrdered::class)]
class IsOrderedTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string, null|int, list<int>}>
     */
    public static function moves(): iterable
    {
        yield 'to the front' => [3, 'first', null, [3, 0, 1, 2]];
        yield 'to the back' => [0, 'last', null, [1, 2, 3, 0]];
        yield 'before an anchor' => [3, 'before', 1, [0, 3, 1, 2]];
        yield 'after an anchor' => [0, 'after', 2, [1, 2, 0, 3]];
    }

    /**
     * @return iterable<string, array{int, Closure(list<Asset>, null|int): OrderChange}>
     */
    public static function unchangedMoves(): iterable
    {
        yield 'a move to where it is' => [3, fn (array $assets, null|int $expected): OrderChange => $assets[1]->move(Placement::after($assets[0]->id), $expected)];
        yield 'a move before its successor' => [3, fn (array $assets, null|int $expected): OrderChange => $assets[1]->move(Placement::before($assets[2]->id), $expected)];
        yield 'a move of the first member to the front' => [3, fn (array $assets, null|int $expected): OrderChange => $assets[0]->move(Placement::first(), $expected)];
        yield 'a move of the last member to the back' => [3, fn (array $assets, null|int $expected): OrderChange => $assets[2]->move(Placement::last(), $expected)];
        yield 'a move of the only member' => [1, fn (array $assets, null|int $expected): OrderChange => $assets[0]->move(Placement::first(), $expected)];
        yield 'a move into the parent it is in' => [2, fn (array $assets, null|int $expected): OrderChange => $assets[1]->move(Placement::last()->in($assets[1]->section), $expected)];
        yield 'a move that expects nothing' => [3, fn (array $assets, null|int $expected): OrderChange => $assets[1]->move(Placement::after($assets[0]->id), null)];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function lockedSections(): iterable
    {
        yield 'while the first parent is locked' => [0];
        yield 'while the second parent is locked' => [1];
    }

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

    #[Test]
    public function it_refuses_a_placement_that_names_a_parent_for_a_new_member(): void
    {
        $section = Section::create();

        $this->expectException(LogicException::class);

        (new Asset)->placeAt(Placement::first()->in($section));
    }

    #[Test]
    public function it_rebalances_the_keys_rather_than_place_a_new_member_past_the_longest_key(): void
    {
        $section = Section::create();
        $first = $this->addAsset($section);
        $next = $this->addAsset($section);
        $inserted = [$next->id];

        while (strlen(FractionalIndex::keyBetween($first->rank_key, $next->rank_key)) <= OrderedRelation::MAX_KEY_LENGTH) {
            $next = $this->addAsset($section, Placement::after($first->id));
            array_unshift($inserted, $next->id);
        }

        $longest = $this->longestRankKey($section);

        $asset = $this->addAsset($section, Placement::after($first->id));

        $this->assertLessThan($longest, $this->longestRankKey($section));
        $this->assertOrder([$first->id, $asset->id, ...$inserted], $section);
    }

    #[Test]
    public function it_locks_the_parent_that_a_stale_instance_now_belongs_to_before_it_deletes(): void
    {
        $source = Section::create();
        $destination = Section::create();
        $asset = $this->addAsset($source);
        $stale = Asset::query()->findOrFail($asset->id);

        $asset->section()->associate($destination)->save();
        $revision = $this->storedRevision($source);
        DB::enableQueryLog();

        $stale->delete();

        $this->assertParentLockedBefore('update "assets"', $destination);
        $this->assertSame($revision, $this->storedRevision($source));
        $this->assertSoftDeleted($asset);
    }

    /**
     * @param  list<int>  $expected
     */
    #[DataProvider('moves')]
    #[Test]
    public function it_moves_a_member_within_its_parent(int $moved, string $where, null|int $anchor, array $expected): void
    {
        [$section, $assets] = $this->sectionWith(4);

        $placement = match ($where) {
            'first' => Placement::first(),
            'last' => Placement::last(),
            'before' => Placement::before($assets[(int) $anchor]->id),
            default => Placement::after($assets[(int) $anchor]->id),
        };

        $change = $assets[$moved]->move($placement, expected: 5);

        $ids = array_map(fn (int $index): int => $assets[$index]->id, $expected);

        $this->assertOrder($ids, $section);
        $this->assertSame(array_map(fn (int $id, int $index): array => ['id' => $id, 'position' => $index + 1], $ids, array_keys($ids)), $change->order);
        $this->assertSame([['id' => $assets[$moved]->id, 'position' => array_search($moved, $expected, true) + 1, 'previous_position' => $moved + 1]], $change->changed);
        $this->assertSame($section, $change->relation->parent);
        $this->assertSame(SectionRevision::AssetsOrder, $change->relation->revision);
        $this->assertNull($change->source);
        $this->assertGreaterThan(5, $change->revision);
        $this->assertSame($this->storedRevision($section), $change->revision);
        $this->assertSame($change->revision, $section->concurrencyRevision(SectionRevision::AssetsOrder));
        $this->assertFalse($section->isDirty());
        $this->assertFalse($assets[$moved]->isDirty());
    }

    #[Test]
    public function it_moves_a_member_between_parents(): void
    {
        [$source, [$first, $moved, $third]] = $this->sectionWith(3);
        [$destination, [$anchor, $last]] = $this->sectionWith(2);

        $change = $moved->move(Placement::after($anchor->id)->in($destination), expected: 4);

        $this->assertOrder([$first->id, $third->id], $source);
        $this->assertOrder([$anchor->id, $moved->id, $last->id], $destination);

        $this->assertSame($destination, $change->relation->parent);
        $this->assertSame([['id' => $anchor->id, 'position' => 1], ['id' => $moved->id, 'position' => 2], ['id' => $last->id, 'position' => 3]], $change->order);
        $this->assertSame([['id' => $moved->id, 'position' => 2, 'previous_position' => null]], $change->changed);
        $this->assertGreaterThan(3, $change->revision);
        $this->assertSame($this->storedRevision($destination), $change->revision);
        $this->assertSame($change->revision, $destination->concurrencyRevision(SectionRevision::AssetsOrder));
        $this->assertFalse($destination->isDirty());

        $this->assertNotNull($change->source);
        $this->assertSame($source, $change->source->relation->parent);
        $this->assertSame([['id' => $first->id, 'position' => 1], ['id' => $third->id, 'position' => 2]], $change->source->order);
        $this->assertSame([['id' => $moved->id, 'position' => null, 'previous_position' => 2]], $change->source->changed);
        $this->assertGreaterThan(4, $change->source->revision);
        $this->assertSame($this->storedRevision($source), $change->source->revision);
        $this->assertSame($change->source->revision, $source->concurrencyRevision(SectionRevision::AssetsOrder));
        $this->assertFalse($source->isDirty());

        $this->assertSame($destination->id, $moved->section_id);
        $this->assertSame($destination, $moved->section);
        $this->assertSame($destination, $moved->orderedRelation()->parent);
        $this->assertSame(2, $moved->position);
        $this->assertFalse($moved->isDirty());
    }

    #[Test]
    public function it_locks_both_parents_in_key_order_before_a_move_between_them(): void
    {
        $destination = Section::create();
        [$source, [$moved]] = $this->sectionWith(1);
        DB::enableQueryLog();

        $moved->move(Placement::first()->in($destination), expected: null);

        $statements = DB::getQueryLog();
        $written = array_find_key($statements, fn (array $statement): bool => str_starts_with($statement['query'], 'update "assets"'));
        $locks = array_filter($statements, fn (array $statement): bool => str_contains($statement['query'], 'from "sections"') && str_ends_with($statement['query'], 'for no key update'));

        $this->assertSame([$destination->id, $source->id], array_values(array_map(fn (array $statement): mixed => $statement['bindings'][0], $locks)));
        $this->assertIsInt($written, 'The member was never written.');
        $this->assertLessThan($written, max(array_keys($locks)));
    }

    #[Test]
    public function it_rejects_a_stale_move_and_changes_nothing(): void
    {
        [$section, [$first, $second, $third]] = $this->sectionWith(3);

        $this->assertRejected(StaleConcurrencyRevision::class, fn (): OrderChange => $third->move(Placement::first(), expected: 3), $section);
        $this->assertOrder([$first->id, $second->id, $third->id], $section);
    }

    #[Test]
    public function it_compares_the_expected_revision_with_the_parent_that_a_member_leaves(): void
    {
        [$source, [$moved]] = $this->sectionWith(1);
        [$destination] = $this->sectionWith(2);

        $this->assertRejected(StaleConcurrencyRevision::class, fn (): OrderChange => $moved->move(Placement::first()->in($destination), expected: 3), $source, $destination);

        $moved->move(Placement::first()->in($destination), expected: 2);

        $this->assertSame($destination->id, $moved->section_id);
    }

    #[Test]
    public function it_moves_without_a_comparison_when_nothing_is_expected(): void
    {
        [$section, [$first, $second]] = $this->sectionWith(2);
        DB::table('sections')->where('id', $section->id)->update(['assets_order_concurrency_revision' => 40]);

        $change = $second->move(Placement::first(), expected: null);

        $this->assertOrder([$second->id, $first->id], $section);
        $this->assertGreaterThan(40, $change->revision);
        $this->assertSame($this->storedRevision($section), $change->revision);
    }

    #[Test]
    public function it_rejects_a_move_anchored_on_the_moved_member(): void
    {
        [$section, [$first, $second]] = $this->sectionWith(2);

        $this->assertRejected(InvalidAnchor::class, fn (): OrderChange => $second->move(Placement::before($second->id), expected: 3), $section);
        $this->assertOrder([$first->id, $second->id], $section);
    }

    #[Test]
    public function it_rejects_a_move_anchored_outside_its_destination(): void
    {
        [$section, [$first, $second]] = $this->sectionWith(2);
        [$other, [$foreign]] = $this->sectionWith(1);

        $this->assertRejected(InvalidAnchor::class, fn (): OrderChange => $second->move(Placement::before($foreign->id), expected: 3), $section, $other);
        $this->assertOrder([$first->id, $second->id], $section);
    }

    #[Test]
    public function it_changes_neither_parent_when_a_move_between_them_fails(): void
    {
        [$source, [$first, $moved]] = $this->sectionWith(2);
        [$destination, [$member]] = $this->sectionWith(1);

        $this->assertRejected(InvalidAnchor::class, fn (): OrderChange => $moved->move(Placement::before($first->id)->in($destination), expected: 3), $source, $destination);
        $this->assertOrder([$first->id, $moved->id], $source);
        $this->assertOrder([$member->id], $destination);
        $this->assertSame($source->id, $moved->section_id);
    }

    #[Test]
    public function it_rejects_a_move_of_a_member_moved_since_it_was_read(): void
    {
        [$source, [$first, $moved]] = $this->sectionWith(2);
        [$elsewhere, [$member]] = $this->sectionWith(1);
        $stale = Asset::query()->findOrFail($moved->id);

        $moved->move(Placement::last()->in($elsewhere), expected: 3);

        $this->assertSame(3, $this->storedRevision($elsewhere));
        $this->assertRejected(StaleConcurrencyRevision::class, fn (): OrderChange => $stale->move(Placement::first(), expected: 3), $source, $elsewhere);
        $this->assertOrder([$first->id], $source);
        $this->assertOrder([$member->id, $moved->id], $elsewhere);
    }

    #[Test]
    public function it_moves_a_member_from_where_it_is_now_when_nothing_is_expected(): void
    {
        [$source, [$first, $moved]] = $this->sectionWith(2);
        [$elsewhere, [$member]] = $this->sectionWith(1);
        $stale = Asset::query()->findOrFail($moved->id);

        $moved->move(Placement::last()->in($elsewhere), expected: null);

        $change = $stale->move(Placement::first(), expected: null);

        $this->assertSame($elsewhere->id, $change->relation->parent->getKey());
        $this->assertOrder([$first->id], $source);
        $this->assertOrder([$moved->id, $member->id], $elsewhere);
    }

    #[Test]
    public function it_rejects_a_move_of_a_member_deleted_since_it_was_read(): void
    {
        [$section, [$first, $deleted]] = $this->sectionWith(2);

        Asset::query()->findOrFail($deleted->id)->delete();

        $this->assertRejected(StaleConcurrencyRevision::class, fn (): OrderChange => $deleted->move(Placement::first(), expected: 3), $section);
        $this->assertRejected(LogicException::class, fn (): OrderChange => $deleted->move(Placement::first(), expected: null), $section);
        $this->assertOrder([$first->id], $section);
    }

    #[Test]
    public function it_rejects_a_destination_that_does_not_order_the_member(): void
    {
        [$section, [$asset]] = $this->sectionWith(1);
        $video = new Video;
        $video->section()->associate($section);
        $video->save();

        $this->assertRejected(LogicException::class, fn (): OrderChange => $asset->move(Placement::first()->in($video), expected: null), $section);
    }

    #[Test]
    public function it_refuses_to_move_a_member_that_is_not_saved(): void
    {
        $asset = new Asset(['section_id' => Section::create()->id]);

        try {
            $asset->move(Placement::first(), expected: null);
            $this->fail('A member moved before it was saved.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('must be saved before it moves', $exception->getMessage());
        }
    }

    /**
     * @param  Closure(list<Asset>, null|int): OrderChange  $move
     */
    #[DataProvider('unchangedMoves')]
    #[Test]
    public function it_writes_nothing_for_a_move_that_keeps_the_order(int $count, Closure $move): void
    {
        [$section, $assets] = $this->sectionWith($count);
        $revision = $this->storedRevision($section);
        $keys = $this->rankKeys($section);
        $ids = array_map(fn (Asset $asset): int => $asset->id, $assets);
        DB::enableQueryLog();

        $change = $move($assets, $revision);

        $this->assertSame([], $this->writes());
        $this->assertSame([], $change->changed);
        $this->assertSame($revision, $change->revision);
        $this->assertSame(array_map(fn (int $id, int $index): array => ['id' => $id, 'position' => $index + 1], $ids, array_keys($ids)), $change->order);
        $this->assertSame($revision, $this->storedRevision($section));
        $this->assertSame($keys, $this->rankKeys($section));
    }

    #[Test]
    public function it_rebalances_the_keys_rather_than_move_a_member_past_the_longest_key(): void
    {
        [$section, [$first, $second, $third]] = $this->sectionWith(3);
        [$moving, $other] = [$third, $second];
        $longest = 0;
        $rebalanced = false;

        for ($attempt = 0; ! $rebalanced && $attempt < 1000; $attempt++) {
            [$moving, $other] = $attempt % 2 === 0 ? [$third, $second] : [$second, $third];

            $change = $moving->move(Placement::after($first->id), expected: null);

            $this->assertSame([$first->id, $moving->id, $other->id], array_column($change->order, 'id'));
            $this->assertSame([['id' => $moving->id, 'position' => 2, 'previous_position' => 3]], $change->changed);

            $length = strlen((string) $moving->rank_key);
            $rebalanced = $length < $longest;
            $longest = $length;
        }

        $this->assertTrue($rebalanced);
        $this->assertLessThanOrEqual(OrderedRelation::MAX_KEY_LENGTH, $this->longestRankKey($section));
        $this->assertOrder([$first->id, $moving->id, $other->id], $section);
    }

    #[Test]
    public function it_rebalances_the_destination_of_a_move_between_parents(): void
    {
        [$source, [$staying, $moved]] = $this->sectionWith(2);
        [$destination, [$first, $next]] = $this->sectionWith(2);
        $inserted = [$next->id];

        while (strlen(FractionalIndex::keyBetween($first->rank_key, $next->rank_key)) <= OrderedRelation::MAX_KEY_LENGTH) {
            $next = $this->addAsset($destination, Placement::after($first->id));
            array_unshift($inserted, $next->id);
        }

        $longest = $this->longestRankKey($destination);

        $change = $moved->move(Placement::after($first->id)->in($destination), expected: null);

        $this->assertLessThan($longest, $this->longestRankKey($destination));
        $this->assertSame([['id' => $moved->id, 'position' => 2, 'previous_position' => null]], $change->changed);
        $this->assertOrder([$first->id, $moved->id, ...$inserted], $destination);
        $this->assertOrder([$staying->id], $source);
    }

    #[Test]
    public function it_lets_one_of_two_concurrent_moves_with_one_expected_revision_succeed(): void
    {
        $racer = $this->racer();
        [$section, [$first, $second, $third]] = $this->racingSectionWith(3);
        $rivals = [];

        try {
            $racer->beginTransaction();

            $change = $third->move(Placement::first(), expected: 4);

            $rivals[] = $this->startMove($second, $section, 4);
            $this->awaitLockWait(1, ...$rivals);

            $racer->commit();

            $result = $rivals[0]->wait();

            $this->assertSame('stale', $result->output(), $result->errorOutput());
            $this->assertSame($this->storedRevision($section), $change->revision);
            $this->assertOrder([$third->id, $first->id, $second->id], $section);
        } finally {
            $this->finishRace($rivals, $section);
        }
    }

    #[DataProvider('lockedSections')]
    #[Test]
    public function it_finishes_opposite_moves_between_two_parents_without_a_deadlock(int $locked): void
    {
        $racer = $this->racer();
        [$first, [$leaving, $stayingFirst]] = $this->racingSectionWith(2);
        [$second, [$arriving, $stayingSecond]] = $this->racingSectionWith(2);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('sections')->where('id', [$first, $second][$locked]->id)->lockForUpdate()->first();

            $rivals[] = $this->startMove($arriving, $first, 3);
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startMove($leaving, $second, 3);
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $outputs = array_map(fn (InvokedProcess $rival): string => $rival->wait()->output(), $rivals);

            $this->assertSame(['moved', 'stale'], $outputs, implode(PHP_EOL, array_map(fn (InvokedProcess $rival): string => $rival->errorOutput(), $rivals)));
            $this->assertOrder([$arriving->id, $leaving->id, $stayingFirst->id], $first);
            $this->assertOrder([$stayingSecond->id], $second);
        } finally {
            $this->finishRace($rivals, $first, $second);
        }
    }

    #[Test]
    public function it_deletes_a_member_from_the_parent_it_moved_to_while_the_delete_waited(): void
    {
        $racer = $this->racer();
        [$source, [$moved, $staying]] = $this->racingSectionWith(2);
        [$destination] = $this->racingSectionWith(0);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('sections')->where('id', $source->id)->lockForUpdate()->first();

            $rivals[] = $this->startDelete($moved);
            $this->awaitLockWait(1, ...$rivals);

            $moved->move(Placement::first()->in($destination), expected: 3);

            $racer->commit();

            $result = $rivals[0]->wait();

            $this->assertSame('deleted', $result->output(), $result->errorOutput());
            $this->assertSoftDeleted('assets', ['id' => $moved->id]);
            $this->assertSame(4, $this->storedRevision($source));
            $this->assertGreaterThan(2, $this->storedRevision($destination));
            $this->assertOrder([$staying->id], $source);
            $this->assertOrder([], $destination);
        } finally {
            $this->finishRace($rivals, $source, $destination);
        }
    }

    #[Test]
    public function it_locks_the_parent_that_a_member_moves_to_after_its_delete_read_the_old_one(): void
    {
        $racer = $this->racer();
        config()->set('database.connections.mover', config('database.connections.pgsql'));
        [$source, [$asset]] = $this->racingSectionWith(1);
        [$destination] = $this->racingSectionWith(0);
        $deleting = Asset::on('racer')->findOrFail($asset->id);
        $moved = false;

        try {
            DB::listen(function (QueryExecuted $query) use (&$moved, $asset, $destination): void {
                if ($moved || $query->connectionName !== 'racer' || ! str_starts_with($query->sql, 'select "section_id" from "assets"')) {
                    return;
                }

                $moved = true;

                Asset::on('mover')->findOrFail($asset->id)->move(Placement::first()->in(Section::on('mover')->findOrFail($destination->id)), expected: null);
            });

            $racer->enableQueryLog();

            $deleting->delete();

            $statements = $racer->getQueryLog();
            $written = array_find_key($statements, fn (array $statement): bool => str_starts_with($statement['query'], 'update "assets"'));
            $locks = array_filter($statements, fn (array $statement, int $index): bool => $index < $written && str_contains($statement['query'], 'from "sections"') && str_ends_with($statement['query'], 'for no key update'), ARRAY_FILTER_USE_BOTH);

            $this->assertTrue($moved, 'The member never moved while the delete ran.');
            $this->assertIsInt($written, 'The member was never written.');
            $this->assertNotSame([], $locks, 'No parent was locked before the write.');
            $this->assertSame($destination->id, end($locks)['bindings'][0]);
            $this->assertSoftDeleted('assets', ['id' => $asset->id]);
            $this->assertOrder([], $destination);
        } finally {
            $this->finishRace([], $source, $destination);
        }
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

    private function assertParentLockedBefore(string $write, null|Section $parent = null): void
    {
        $statements = DB::getQueryLog();

        $lock = array_find_key($statements, fn (array $statement): bool => str_contains($statement['query'], 'from "sections"')
            && str_ends_with($statement['query'], 'for no key update')
            && ($parent === null || $statement['bindings'] === [$parent->id]));
        $written = array_find_key($statements, fn (array $statement): bool => str_starts_with($statement['query'], $write));

        $this->assertIsInt($lock, 'The parent row was never locked.');
        $this->assertIsInt($written, 'The item was never written.');
        $this->assertLessThan($written, $lock);
    }

    /**
     * @param  class-string<Throwable>  $exception
     * @param  Closure(): mixed  $change
     */
    private function assertRejected(string $exception, Closure $change, Section ...$sections): void
    {
        $before = array_map(fn (Section $section): array => [$this->storedRevision($section), $this->rankKeys($section)], $sections);
        $thrown = null;

        try {
            $change();
        } catch (Throwable $thrown) {
        }

        $this->assertInstanceOf($exception, $thrown);
        $this->assertSame($before, array_map(fn (Section $section): array => [$this->storedRevision($section), $this->rankKeys($section)], $sections));
    }

    /**
     * @return array{Section, list<Asset>}
     */
    private function sectionWith(int $count): array
    {
        $section = Section::create();
        $assets = [];

        for ($index = 0; $index < $count; $index++) {
            $assets[] = $this->addAsset($section);
        }

        return [$section, $assets];
    }

    /**
     * @return array<int, null|string>
     */
    private function rankKeys(Section $section): array
    {
        /** @var array<int, null|string> */
        return DB::table('assets')->where('section_id', $section->id)->orderBy('id')->pluck('rank_key', 'id')->all();
    }

    private function longestRankKey(Section $section): int
    {
        return (int) DB::table('assets')->where('section_id', $section->id)->max(DB::raw('length(rank_key)'));
    }

    /**
     * @return list<string>
     */
    private function writes(): array
    {
        return array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $statement): bool => preg_match('/^\s*(insert|update|delete)\b/i', $statement) === 1,
        ));
    }

    private function storedRevision(Section $section, SectionRevision $revision = SectionRevision::AssetsOrder): int
    {
        return (int) DB::table('sections')->where('id', $section->id)->value($revision->value.'_concurrency_revision');
    }

    private function racer(): Connection
    {
        config()->set('database.connections.racer', config('database.connections.pgsql'));
        config()->set('database.connections.observer', config('database.connections.pgsql'));

        return DB::connection('racer');
    }

    /**
     * @return array{Section, list<Asset>}
     */
    private function racingSectionWith(int $count): array
    {
        $section = Section::on('racer')->create();
        $assets = [];

        for ($index = 0; $index < $count; $index++) {
            $asset = (new Asset)->setConnection('racer');
            $asset->section()->associate($section);
            $asset->save();

            $assets[] = $asset;
        }

        return [$section, $assets];
    }

    private function startMove(Asset $asset, Section $destination, int $expected): InvokedProcess
    {
        return Process::env($this->databaseEnvironment())->start([
            PHP_BINARY,
            dirname(__DIR__, 6).'/tests/Fixtures/Support/Concurrency/move.php',
            (string) $asset->id,
            (string) $destination->id,
            (string) $expected,
        ]);
    }

    private function startDelete(Asset $asset): InvokedProcess
    {
        return Process::env($this->databaseEnvironment())->start([
            PHP_BINARY,
            dirname(__DIR__, 6).'/tests/Fixtures/Support/Concurrency/delete.php',
            (string) $asset->id,
        ]);
    }

    private function awaitLockWait(int $waiters, InvokedProcess ...$rivals): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            foreach ($rivals as $rival) {
                $this->assertTrue($rival->running(), $rival->errorOutput());
            }

            $waiting = intval(DB::connection('observer')->scalar("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'"));

            if ($waiting >= $waiters) {
                return;
            }

            usleep(10_000);
        }

        $this->fail('The rivals never waited on the row locks.');
    }

    /**
     * @param  list<InvokedProcess>  $rivals
     */
    private function finishRace(array $rivals, Section ...$sections): void
    {
        $racer = DB::connection('racer');

        if ($racer->transactionLevel() > 0) {
            $racer->rollBack();
        }

        foreach ($rivals as $rival) {
            $rival->wait();
        }

        $ids = array_map(fn (Section $section): int => $section->id, $sections);

        $racer->table('assets')->whereIn('section_id', $ids)->delete();
        $racer->table('sections')->whereIn('id', $ids)->delete();
    }

    /**
     * @return array<string, string>
     */
    private function databaseEnvironment(): array
    {
        return [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => config()->string('database.connections.pgsql.host'),
            'DB_PORT' => config()->string('database.connections.pgsql.port'),
            'DB_DATABASE' => config()->string('database.connections.pgsql.database'),
            'DB_USERNAME' => config()->string('database.connections.pgsql.username'),
            'DB_PASSWORD' => config()->string('database.connections.pgsql.password'),
        ];
    }
}
