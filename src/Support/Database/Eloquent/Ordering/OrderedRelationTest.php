<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Exceptions\MembershipMismatch;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\TestCase;
use Throwable;

#[CoversClass(MembershipMismatch::class)]
#[CoversClass(OrderChange::class)]
#[CoversClass(OrderedRelation::class)]
class OrderedRelationTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(list<int>, int): list<int|string>}>
     */
    public static function mismatches(): iterable
    {
        yield 'a member is missing' => [fn (array $ids, int $foreign): array => [$ids[2], $ids[1]]];
        yield 'an id repeats' => [fn (array $ids, int $foreign): array => [$ids[2], $ids[1], $ids[0], $ids[0]]];
        yield 'an id repeats as a string' => [fn (array $ids, int $foreign): array => [$ids[2], $ids[1], $ids[0], (string) $ids[0]]];
        yield 'an id is foreign' => [fn (array $ids, int $foreign): array => [$ids[2], $ids[1], $ids[0], $foreign]];
        yield 'a foreign id replaces a member' => [fn (array $ids, int $foreign): array => [$ids[2], $ids[1], $foreign]];
    }

    /**
     * @return iterable<string, array{int, Closure(list<int>): list<int>, bool}>
     */
    public static function unchangedReorders(): iterable
    {
        yield 'the current order' => [3, fn (array $ids): array => $ids, true];
        yield 'the current order without a comparison' => [3, fn (array $ids): array => $ids, false];
        yield 'the only member' => [1, fn (array $ids): array => $ids, true];
        yield 'an empty order' => [0, fn (array $ids): array => [], true];
    }

    #[Test]
    public function it_queries_the_members_in_order(): void
    {
        $section = Section::create();
        $third = $this->addAsset($section);
        $first = $this->addAsset($section, Placement::first());
        $second = $this->addAsset($section, Placement::after($first->id));
        $this->addAsset(Section::create());

        $this->assertSame([$first->id, $second->id, $third->id], $this->members($section));
    }

    #[Test]
    public function it_queries_through_the_parents_relation(): void
    {
        $section = Section::create();

        $query = $section->orderedRelation(SectionRevision::AssetsOrder)->query();

        $this->assertInstanceOf(HasMany::class, $query);
        $this->assertSame($section, $query->getParent());
    }

    #[Test]
    public function it_drops_every_global_scope_except_soft_deletes(): void
    {
        $section = Section::create();
        $hidden = $this->addAsset($section, attributes: ['visible' => false]);
        $trashed = $this->addAsset($section);
        $visible = $this->addAsset($section);

        $trashed->delete();

        $this->assertSame([$hidden->id, $visible->id], $this->members($section));
    }

    #[Test]
    public function it_reads_the_concurrency_revision_from_the_parent_row(): void
    {
        $section = Section::create();

        DB::table('sections')->where('id', $section->id)->update(['assets_order_concurrency_revision' => 7]);

        $this->assertSame(7, $section->orderedRelation(SectionRevision::AssetsOrder)->concurrencyRevision());
        $this->assertSame(1, $section->concurrencyRevision(SectionRevision::AssetsOrder));
    }

    #[Test]
    public function it_reorders_every_member(): void
    {
        [$section, [$first, $second, $third, $fourth]] = $this->sectionWith(4);
        $relation = $section->orderedRelation(SectionRevision::AssetsOrder);

        $change = $relation->reorder([(string) $third->id, (string) $second->id, (string) $first->id, (string) $fourth->id], expected: 5);

        $this->assertSame([$third->id, $second->id, $first->id, $fourth->id], $this->members($section));
        $this->assertSame([
            ['id' => $third->id, 'position' => 1],
            ['id' => $second->id, 'position' => 2],
            ['id' => $first->id, 'position' => 3],
            ['id' => $fourth->id, 'position' => 4],
        ], $change->order);
        $this->assertSame([
            ['id' => $third->id, 'position' => 1, 'previous_position' => 3],
            ['id' => $first->id, 'position' => 3, 'previous_position' => 1],
        ], $change->changed);
        $this->assertSame($relation, $change->relation);
        $this->assertNull($change->source);
        $this->assertGreaterThan(5, $change->revision);
        $this->assertSame($this->storedRevision($section), $change->revision);
        $this->assertSame($change->revision, $section->concurrencyRevision(SectionRevision::AssetsOrder));
        $this->assertFalse($section->isDirty());
    }

    #[Test]
    public function it_locks_the_parent_before_it_reorders(): void
    {
        [$section, [$first, $second]] = $this->sectionWith(2);
        DB::enableQueryLog();

        $section->orderedRelation(SectionRevision::AssetsOrder)->reorder([$second->id, $first->id], expected: 3);

        $statements = array_column(DB::getQueryLog(), 'query');
        $lock = array_find_key($statements, fn (string $statement): bool => str_contains($statement, 'from "sections"') && str_ends_with($statement, 'for no key update'));
        $written = array_find_key($statements, fn (string $statement): bool => str_starts_with($statement, 'update "assets"'));

        $this->assertIsInt($lock, 'The parent row was never locked.');
        $this->assertIsInt($written, 'No member was written.');
        $this->assertLessThan($written, $lock);
    }

    /**
     * @param  Closure(list<int>, int): list<int|string>  $ids
     */
    #[DataProvider('mismatches')]
    #[Test]
    public function it_rejects_a_reorder_that_does_not_name_each_member_once(Closure $ids): void
    {
        [$section, $assets] = $this->sectionWith(3);
        [, [$foreign]] = $this->sectionWith(1);
        $members = array_map(fn (Asset $asset): int => $asset->id, $assets);

        $this->assertRejected(MembershipMismatch::class, $section, fn (): OrderChange => $section->orderedRelation(SectionRevision::AssetsOrder)->reorder($ids($members, $foreign->id), expected: 4));
        $this->assertSame($members, $this->members($section));
    }

    #[Test]
    public function it_rejects_a_reorder_that_names_a_trashed_member(): void
    {
        [$section, [$first, $trashed, $third]] = $this->sectionWith(3);
        $trashed->delete();
        $revision = $this->storedRevision($section);

        $this->assertRejected(MembershipMismatch::class, $section, fn (): OrderChange => $section->orderedRelation(SectionRevision::AssetsOrder)->reorder([$third->id, $trashed->id, $first->id], expected: $revision));
    }

    #[Test]
    public function it_rejects_a_stale_reorder_and_changes_nothing(): void
    {
        [$section, [$first, $second]] = $this->sectionWith(2);

        $this->assertRejected(StaleConcurrencyRevision::class, $section, fn (): OrderChange => $section->orderedRelation(SectionRevision::AssetsOrder)->reorder([$second->id, $first->id], expected: 2));
        $this->assertSame([$first->id, $second->id], $this->members($section));
    }

    #[Test]
    public function it_rejects_a_stale_reorder_that_keeps_the_order(): void
    {
        [$section, [$first, $second]] = $this->sectionWith(2);

        $this->assertRejected(StaleConcurrencyRevision::class, $section, fn (): OrderChange => $section->orderedRelation(SectionRevision::AssetsOrder)->reorder([$first->id, $second->id], expected: 2));
    }

    #[Test]
    public function it_reorders_without_a_comparison_when_nothing_is_expected(): void
    {
        [$section, [$first, $second]] = $this->sectionWith(2);
        DB::table('sections')->where('id', $section->id)->update(['assets_order_concurrency_revision' => 40]);

        $change = $section->orderedRelation(SectionRevision::AssetsOrder)->reorder([$second->id, $first->id], expected: null);

        $this->assertSame([$second->id, $first->id], $this->members($section));
        $this->assertGreaterThan(40, $change->revision);
        $this->assertSame($this->storedRevision($section), $change->revision);
    }

    /**
     * @param  Closure(list<int>): list<int>  $reorder
     */
    #[DataProvider('unchangedReorders')]
    #[Test]
    public function it_writes_nothing_for_a_reorder_that_keeps_the_order(int $count, Closure $reorder, bool $compare): void
    {
        [$section, $assets] = $this->sectionWith($count);
        $relation = $section->orderedRelation(SectionRevision::AssetsOrder);
        $revision = $this->storedRevision($section);
        $keys = $this->rankKeys($section);
        $ids = array_map(fn (Asset $asset): int => $asset->id, $assets);
        DB::enableQueryLog();

        $change = $relation->reorder($reorder($ids), $compare ? $revision : null);

        $this->assertSame([], $this->writes());
        $this->assertSame([], $change->changed);
        $this->assertSame($revision, $change->revision);
        $this->assertSame(array_map(fn (int $id, int $index): array => ['id' => $id, 'position' => $index + 1], $ids, array_keys($ids)), $change->order);
        $this->assertSame($revision, $this->storedRevision($section));
        $this->assertSame($keys, $this->rankKeys($section));
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
     * @param  class-string<Throwable>  $exception
     * @param  Closure(): mixed  $change
     */
    private function assertRejected(string $exception, Section $section, Closure $change): void
    {
        $revision = $this->storedRevision($section);
        $keys = $this->rankKeys($section);
        $thrown = null;

        try {
            $change();
        } catch (Throwable $thrown) {
        }

        $this->assertInstanceOf($exception, $thrown);
        $this->assertSame($revision, $this->storedRevision($section));
        $this->assertSame($keys, $this->rankKeys($section));
    }

    /**
     * @return array<int, null|string>
     */
    private function rankKeys(Section $section): array
    {
        /** @var array<int, null|string> */
        return DB::table('assets')->where('section_id', $section->id)->orderBy('id')->pluck('rank_key', 'id')->all();
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

    private function storedRevision(Section $section): int
    {
        return (int) DB::table('sections')->where('id', $section->id)->value('assets_order_concurrency_revision');
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
     * @return list<int>
     */
    private function members(Section $section): array
    {
        /** @var list<int> */
        return $section->orderedRelation(SectionRevision::AssetsOrder)->query()->pluck('id')->all();
    }
}
