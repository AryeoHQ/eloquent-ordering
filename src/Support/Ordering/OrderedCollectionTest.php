<?php

declare(strict_types=1);

namespace Support\Ordering;

use Closure;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Ordering\Exceptions\InvalidAnchor;
use Support\Ordering\Exceptions\MembershipMismatch;
use Support\Ordering\Exceptions\StaleOrder;
use Support\Ordering\Keys\FractionalIndex;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Assets\OrdersAssets;
use Tests\Fixtures\Support\Scenes\Scene;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Videos\Video;
use Tests\TestCase;
use Throwable;

#[CoversClass(OrderedCollection::class)]
class OrderedCollectionTest extends TestCase
{
    use OrdersAssets;

    /**
     * @return iterable<string, array{int, string, int|null, list<int>}>
     */
    public static function moves(): iterable
    {
        yield 'to the front' => [3, 'first', null, [3, 0, 1, 2]];
        yield 'to the back' => [0, 'last', null, [1, 2, 3, 0]];
        yield 'before an anchor' => [3, 'before', 1, [0, 3, 1, 2]];
        yield 'after an anchor' => [0, 'after', 2, [1, 2, 0, 3]];
    }

    /**
     * @return iterable<string, array{int, Closure(OrderedCollection, list<Asset>, int): OrderChange}>
     */
    public static function unchangedOrders(): iterable
    {
        yield 'a move to where it is' => [3, fn (OrderedCollection $assets, array $members, int $version): OrderChange => $assets->move($members[1], Placement::after($members[0]->id), $version)];
        yield 'a move before its successor' => [3, fn (OrderedCollection $assets, array $members, int $version): OrderChange => $assets->move($members[1], Placement::before($members[2]->id), $version)];
        yield 'a move of the first member to the front' => [3, fn (OrderedCollection $assets, array $members, int $version): OrderChange => $assets->move($members[0], Placement::first(), $version)];
        yield 'a move of the last member to the back' => [3, fn (OrderedCollection $assets, array $members, int $version): OrderChange => $assets->move($members[2], Placement::last(), $version)];
        yield 'a move of the only member' => [1, fn (OrderedCollection $assets, array $members, int $version): OrderChange => $assets->move($members[0], Placement::first(), $version)];
        yield 'a reorder in the current order' => [3, fn (OrderedCollection $assets, array $members, int $version): OrderChange => $assets->reorder([$members[0]->id, $members[1]->id, $members[2]->id], $version)];
        yield 'a reorder of the only member' => [1, fn (OrderedCollection $assets, array $members, int $version): OrderChange => $assets->reorder([$members[0]->id], $version)];
        yield 'a reorder of an empty collection' => [0, fn (OrderedCollection $assets, array $members, int $version): OrderChange => $assets->reorder([], $version)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedAnchors(): iterable
    {
        yield 'a word' => ['abc'];
        yield 'a fraction' => ['1.5'];
        yield 'a number beyond a bigint' => ['99999999999999999999'];
    }

    /**
     * @return iterable<string, array{Closure(list<int>, int): list<int>}>
     */
    public static function mismatches(): iterable
    {
        yield 'a member is missing' => [fn (array $ids, int $foreign): array => [$ids[2], $ids[1]]];
        yield 'an id repeats' => [fn (array $ids, int $foreign): array => [$ids[2], $ids[1], $ids[0], $ids[0]]];
        yield 'an id is foreign' => [fn (array $ids, int $foreign): array => [$ids[2], $ids[1], $ids[0], $foreign]];
        yield 'a foreign id replaces a member' => [fn (array $ids, int $foreign): array => [$ids[2], $ids[1], $foreign]];
    }

    #[Test]
    public function it_reads_the_version_of_the_order(): void
    {
        [$section] = $this->sectionWith(3);

        $this->assertSame(3, $section->orderedCollection('assets')->version());
    }

    #[Test]
    public function it_tags_the_order_with_a_strong_entity_tag(): void
    {
        [$section] = $this->sectionWith(1);

        $tag = $section->orderedCollection('assets')->tag();

        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $tag);
        $this->assertSame($tag, $section->orderedCollection('assets')->tag());
    }

    #[Test]
    public function it_gives_each_order_its_own_tag(): void
    {
        $first = Section::factory()->create()->orderedCollection('assets');
        $second = Section::factory()->create()->orderedCollection('assets');

        $this->assertNotSame($first->tag(), $second->tag());
    }

    #[Test]
    public function it_changes_the_tag_with_every_change_of_order_or_membership_and_nothing_else(): void
    {
        $section = Section::factory()->create();
        $collection = $section->orderedCollection('assets');
        $tags = [$collection->tag()];

        $first = $this->addAsset($section);
        $tags[] = $collection->tag();

        $second = $this->addAsset($section);
        $tags[] = $collection->tag();

        $collection->move($second, Placement::first(), 2);
        $tags[] = $collection->tag();

        $collection->reorder([$first->id, $second->id], 3);
        $tags[] = $collection->tag();

        $first->delete();
        $tags[] = $collection->tag();

        $section->update(['name' => 'Exterior']);
        $second->update(['title' => 'Dusk']);

        $this->assertCount(6, array_unique($tags));
        $this->assertSame(end($tags), $collection->tag());
    }

    /**
     * @param  list<int>  $expected
     */
    #[DataProvider('moves')]
    #[Test]
    public function it_moves_a_member(int $moved, string $where, int|null $anchor, array $expected): void
    {
        [$section, $assets] = $this->sectionWith(4);
        $collection = $section->orderedCollection('assets');

        $placement = match ($where) {
            'first' => Placement::first(),
            'last' => Placement::last(),
            'before' => Placement::before($assets[(int) $anchor]->id),
            default => Placement::after($assets[(int) $anchor]->id),
        };

        $change = $collection->move($assets[$moved], $placement, 4);

        $ids = array_map(fn (int $index): int => $assets[$index]->id, $expected);

        $this->assertOrder($ids, $section);
        $this->assertSame(array_map(fn (int $id, int $index): array => ['id' => $id, 'position' => $index + 1], $ids, array_keys($ids)), $change->order);
        $this->assertSame([['id' => $assets[$moved]->id, 'position' => array_search($moved, $expected, true) + 1, 'previous_position' => $moved + 1]], $change->changed);
        $this->assertSame(5, $change->version);
        $this->assertSame($collection->tag(), $change->tag);
        $this->assertSame($collection, $change->collection);
        $this->assertNull($change->source);
    }

    #[Test]
    public function it_rejects_a_move_anchored_on_the_moved_member(): void
    {
        [$section, [$first, $second]] = $this->sectionWith(2);

        $this->assertRejected(InvalidAnchor::class, $section, fn () => $section->orderedCollection('assets')->move($second, Placement::before($second->id), 2));
        $this->assertOrder([$first->id, $second->id], $section);
    }

    #[Test]
    public function it_rejects_a_move_anchored_outside_the_collection(): void
    {
        [$section, [$first, $second]] = $this->sectionWith(2);
        [, [$foreign]] = $this->sectionWith(1);

        $this->assertRejected(InvalidAnchor::class, $section, fn () => $section->orderedCollection('assets')->move($second, Placement::before($foreign->id), 2));
        $this->assertOrder([$first->id, $second->id], $section);
    }

    #[Test]
    public function it_rejects_a_stale_move_and_changes_nothing(): void
    {
        [$section, [$first, $second, $third]] = $this->sectionWith(3);

        $this->assertRejected(StaleOrder::class, $section, fn () => $section->orderedCollection('assets')->move($third, Placement::first(), 2));
        $this->assertOrder([$first->id, $second->id, $third->id], $section);
    }

    #[Test]
    public function it_reorders_every_member(): void
    {
        [$section, [$first, $second, $third, $fourth]] = $this->sectionWith(4);
        $collection = $section->orderedCollection('assets');

        $change = $collection->reorder([(string) $third->id, (string) $second->id, (string) $first->id, (string) $fourth->id], 4);

        $this->assertOrder([$third->id, $second->id, $first->id, $fourth->id], $section);
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
        $this->assertSame(5, $change->version);
        $this->assertSame($collection->tag(), $change->tag);
    }

    /**
     * @param  Closure(list<int>, int): list<int>  $ids
     */
    #[DataProvider('mismatches')]
    #[Test]
    public function it_rejects_a_reorder_that_does_not_list_each_member_once(Closure $ids): void
    {
        [$section, $assets] = $this->sectionWith(3);
        [, [$foreign]] = $this->sectionWith(1);
        $members = array_map(fn (Asset $asset): int => $asset->id, $assets);

        $this->assertRejected(MembershipMismatch::class, $section, fn () => $section->orderedCollection('assets')->reorder($ids($members, $foreign->id), 3));
        $this->assertOrder($members, $section);
    }

    #[Test]
    public function it_rejects_a_stale_reorder_and_changes_nothing(): void
    {
        [$section, [$first, $second]] = $this->sectionWith(2);

        $this->assertRejected(StaleOrder::class, $section, fn () => $section->orderedCollection('assets')->reorder([$second->id, $first->id], 1));
        $this->assertOrder([$first->id, $second->id], $section);
    }

    /**
     * @param  Closure(OrderedCollection, list<Asset>, int): OrderChange  $change
     */
    #[DataProvider('unchangedOrders')]
    #[Test]
    public function it_keeps_the_version_and_writes_nothing_when_the_order_stays_the_same(int $count, Closure $change): void
    {
        [$section, $assets] = $this->sectionWith($count);
        $collection = $section->orderedCollection('assets');
        $tag = $collection->tag();
        $keys = $this->rankKeys($section);
        $ids = array_map(fn (Asset $asset): int => $asset->id, $assets);

        DB::enableQueryLog();

        $result = $change($collection, $assets, $count);

        $writes = array_filter(DB::getQueryLog(), fn (array $query): bool => preg_match('/^\s*(insert|update|delete)\b/i', $query['query']) === 1);

        $this->assertSame([], array_values($writes));
        $this->assertSame([], $result->changed);
        $this->assertSame($count, $result->version);
        $this->assertSame($tag, $result->tag);
        $this->assertSame(array_map(fn (int $id, int $index): array => ['id' => $id, 'position' => $index + 1], $ids, array_keys($ids)), $result->order);
        $this->assertSame($count, $collection->version());
        $this->assertSame($tag, $collection->tag());
        $this->assertSame($keys, $this->rankKeys($section));
    }

    #[Test]
    public function it_rejects_a_stale_change_that_keeps_the_order(): void
    {
        [$section, [$first, $second]] = $this->sectionWith(2);
        $collection = $section->orderedCollection('assets');

        $this->assertRejected(StaleOrder::class, $section, fn () => $collection->move($first, Placement::first(), 1));
        $this->assertRejected(StaleOrder::class, $section, fn () => $collection->reorder([$first->id, $second->id], 1));
    }

    #[Test]
    public function it_rejects_a_move_of_an_item_that_the_collection_cannot_hold(): void
    {
        [$section, [$first, $second]] = $this->sectionWith(2);
        $video = Video::factory()->for($section)->create(['id' => $second->id]);

        $this->assertRejected(InvalidArgumentException::class, $section, fn () => $section->orderedCollection('assets')->move($video, Placement::first(), 2));
        $this->assertOrder([$first->id, $second->id], $section);
        $this->assertSame(1, $section->orderedCollection('videos')->version());
    }

    #[DataProvider('malformedAnchors')]
    #[Test]
    public function it_rejects_a_malformed_anchor(string $anchor): void
    {
        [$section, [$first, $second]] = $this->sectionWith(2);

        $this->assertRejected(InvalidAnchor::class, $section, fn () => $section->orderedCollection('assets')->move($second, Placement::before($anchor), 2));
        $this->assertRejected(InvalidAnchor::class, $section, fn () => $this->addAsset($section, Placement::after($anchor)));
        $this->assertOrder([$first->id, $second->id], $section);
    }

    #[Test]
    public function it_rejects_a_move_of_a_member_deleted_since_it_was_read(): void
    {
        [$section, [$first, $deleted]] = $this->sectionWith(2);
        [$destination] = $this->sectionWith(0);

        Asset::query()->findOrFail($deleted->id)->delete();

        $this->assertRejected(StaleOrder::class, $section, fn () => $section->orderedCollection('assets')->move($deleted, Placement::first(), 3));
        $this->assertRejected(StaleOrder::class, $destination, fn () => $destination->orderedCollection('assets')->move($deleted, Placement::first(), 0));
        $this->assertOrder([$first->id], $section);
    }

    #[Test]
    public function it_rejects_a_move_of_a_member_moved_since_it_was_read(): void
    {
        [$source, [$first, $moved]] = $this->sectionWith(2);
        [$elsewhere, [$member]] = $this->sectionWith(1);
        [$destination] = $this->sectionWith(0);

        $elsewhere->orderedCollection('assets')->move(Asset::query()->findOrFail($moved->id), Placement::last(), 1);

        $this->assertRejected(StaleOrder::class, $source, fn () => $source->orderedCollection('assets')->move($moved, Placement::first(), 3));
        $this->assertRejected(StaleOrder::class, $destination, fn () => $destination->orderedCollection('assets')->move($moved, Placement::first(), 0));
        $this->assertOrder([$first->id], $source);
        $this->assertOrder([$member->id, $moved->id], $elsewhere);
    }

    #[Test]
    public function it_moves_a_member_between_two_collections_in_one_transaction(): void
    {
        [$source, [$first, $moved, $third]] = $this->sectionWith(3);
        [$destination, [$anchor, $last]] = $this->sectionWith(2);

        $change = $destination->orderedCollection('assets')->move($moved, Placement::after($anchor->id), 2);

        $this->assertOrder([$first->id, $third->id], $source);
        $this->assertOrder([$anchor->id, $moved->id, $last->id], $destination);
        $this->assertSame([['id' => $moved->id, 'position' => 2, 'previous_position' => null]], $change->changed);
        $this->assertSame(3, $change->version);
        $this->assertSame($destination->orderedCollection('assets')->tag(), $change->tag);

        $this->assertNotNull($change->source);
        $this->assertSame([['id' => $first->id, 'position' => 1], ['id' => $third->id, 'position' => 2]], $change->source->order);
        $this->assertSame([['id' => $moved->id, 'position' => null, 'previous_position' => 2]], $change->source->changed);
        $this->assertSame(4, $change->source->version);
        $this->assertSame($source->orderedCollection('assets')->tag(), $change->source->tag);

        $this->assertSame($destination->id, $moved->section_id);
        $this->assertSame($destination, $moved->section);
    }

    #[Test]
    public function it_changes_neither_collection_when_a_move_between_them_fails(): void
    {
        [$source, [$first, $moved]] = $this->sectionWith(2);
        [$destination, [$member]] = $this->sectionWith(1);

        $this->assertRejected(InvalidAnchor::class, $source, fn () => $destination->orderedCollection('assets')->move($moved, Placement::before($first->id), 1));
        $this->assertSame(1, $destination->orderedCollection('assets')->version());
        $this->assertOrder([$first->id, $moved->id], $source);
        $this->assertOrder([$member->id], $destination);
    }

    #[Test]
    public function it_rebalances_without_changing_positions_or_the_version(): void
    {
        [$section, [$first, $second, $third]] = $this->sectionWith(3);
        $collection = $section->orderedCollection('assets');
        $longest = 0;
        $rebalanced = false;

        for ($version = 3; ! $rebalanced && $version < 1000; $version++) {
            [$moving, $other] = $version % 2 === 0 ? [$second, $third] : [$third, $second];

            $change = $collection->move($moving, Placement::after($first->id), $version);

            $this->assertSame($version + 1, $change->version);
            $this->assertSame([$first->id, $moving->id, $other->id], array_column($change->order, 'id'));

            $rebalanced = strlen($moving->rank_key) < $longest;
            $longest = strlen($moving->rank_key);
        }

        $this->assertTrue($rebalanced);
        $this->assertLessThanOrEqual(64, max(array_map(strlen(...), $this->rankKeys($section))));
    }

    #[Test]
    public function it_rebalances_the_destination_of_a_move_between_two_collections(): void
    {
        [$source, [$staying, $moved]] = $this->sectionWith(2);
        [$destination, [$first, $last]] = $this->sectionWith(2);
        $collection = $destination->orderedCollection('assets');
        $next = $last;
        $inserted = [];

        while (strlen(FractionalIndex::keyBetween($first->rank_key, $next->rank_key)) <= OrderedCollection::MAX_KEY_LENGTH) {
            $next = $this->addAsset($destination, Placement::after($first->id));
            array_unshift($inserted, $next->id);
        }

        $version = $collection->version();
        $longest = max(array_map(strlen(...), $this->rankKeys($destination)));

        $change = $collection->move($moved, Placement::after($first->id), $version);

        $this->assertSame($version + 1, $change->version);
        $this->assertLessThan($longest, max(array_map(strlen(...), $this->rankKeys($destination))));
        $this->assertLessThanOrEqual(OrderedCollection::MAX_KEY_LENGTH, strlen($moved->rank_key));
        $this->assertOrder([$first->id, $moved->id, ...$inserted, $last->id], $destination);
        $this->assertOrder([$staying->id], $source);
    }

    #[Test]
    public function it_orders_members_with_uuid_keys(): void
    {
        $tour = Tour::factory()->create();
        $collection = $tour->orderedCollection('scenes');

        $second = $this->addScene($tour);
        $fourth = $this->addScene($tour);
        $first = $this->addScene($tour, Placement::first());
        $third = $this->addScene($tour, Placement::after($second->id));

        $this->assertSceneOrder([$first, $second, $third, $fourth], $tour);

        $collection->move($fourth, Placement::before($second->id), 4);
        $this->assertSceneOrder([$first, $fourth, $second, $third], $tour);

        $change = $collection->reorder([$third->id, $second->id, $fourth->id, $first->id], 5);
        $this->assertSame([$third->id, $second->id, $fourth->id, $first->id], array_column($change->order, 'id'));
        $this->assertSceneOrder([$third, $second, $fourth, $first], $tour);

        $other = Tour::factory()->create();
        $member = $this->addScene($other);

        $change = $other->orderedCollection('scenes')->move($third, Placement::before($member->id), 1);
        $this->assertSame(2, $change->version);
        $this->assertSame(7, $change->source?->version);
        $this->assertSceneOrder([$third, $member], $other);
        $this->assertSceneOrder([$second, $fourth, $first], $tour);

        foreach (['abc', 42] as $anchor) {
            try {
                $collection->move($first, Placement::before($anchor), 7);
                $this->fail('A malformed anchor moved a member.');
            } catch (InvalidAnchor) {
                $this->assertSame(7, $collection->version());
            }
        }

        $this->assertSceneOrder([$second, $fourth, $first], $tour);
    }

    #[Test]
    public function it_lets_one_of_two_concurrent_moves_with_one_expected_version_succeed(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The race needs PostgreSQL row locks across two connections.');
        }

        config()->set('database.connections.racer', config('database.connections.pgsql'));
        config()->set('database.connections.observer', config('database.connections.pgsql'));

        $racer = DB::connection('racer');
        $section = Section::factory()->connection('racer')->create();
        [$first, $second, $third] = Asset::factory()->connection('racer')->for($section)->count(3)->create()->all();
        $rival = null;

        try {
            $racer->beginTransaction();

            $change = $section->orderedCollection('assets')->move($third, Placement::first(), 3);

            $rival = Process::env($this->databaseEnvironment())->start([
                PHP_BINARY,
                dirname(__DIR__, 3).'/tests/Fixtures/Support/Concurrency/move.php',
                (string) $section->id,
                (string) $second->id,
                '3',
            ]);

            $this->awaitLockWait(1, $rival);

            $racer->commit();

            $result = $rival->wait();

            $this->assertSame('stale', $result->output(), $result->errorOutput());
            $this->assertSame(4, $change->version);
            $this->assertSame(4, $section->orderedCollection('assets')->version());
            $this->assertOrder([$third->id, $first->id, $second->id], $section);
        } finally {
            if ($racer->transactionLevel() > 0) {
                $racer->rollBack();
            }

            $rival?->wait();

            $racer->table('assets')->where('section_id', $section->id)->delete();
            $racer->table('sections')->where('id', $section->id)->delete();
        }
    }

    #[Test]
    public function it_finishes_opposite_moves_between_two_collections_without_a_deadlock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The race needs PostgreSQL row locks across three connections.');
        }

        config()->set('database.connections.racer', config('database.connections.pgsql'));
        config()->set('database.connections.observer', config('database.connections.pgsql'));

        $racer = DB::connection('racer');
        $first = Section::factory()->connection('racer')->create();
        $second = Section::factory()->connection('racer')->create();
        [$leaving, $stayingFirst] = Asset::factory()->connection('racer')->for($first)->count(2)->create()->all();
        [$arriving, $stayingSecond] = Asset::factory()->connection('racer')->for($second)->count(2)->create()->all();
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('sections')->where('id', $first->id)->lockForUpdate()->first();

            $rivals[] = $this->startMove($first, $arriving, 2);
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startMove($second, $leaving, 2);
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $outputs = array_map(fn (InvokedProcess $rival): string => $rival->wait()->output(), $rivals);

            foreach ($rivals as $index => $rival) {
                $this->assertContains($outputs[$index], ['moved', 'stale'], $rival->errorOutput());
            }

            $this->assertSame(['moved', 'stale'], $outputs);
            $this->assertOrder([$arriving->id, $leaving->id, $stayingFirst->id], $first);
            $this->assertOrder([$stayingSecond->id], $second);
        } finally {
            if ($racer->transactionLevel() > 0) {
                $racer->rollBack();
            }

            foreach ($rivals as $rival) {
                $rival->wait();
            }

            $racer->table('assets')->whereIn('section_id', [$first->id, $second->id])->delete();
            $racer->table('sections')->whereIn('id', [$first->id, $second->id])->delete();
        }
    }

    #[Test]
    public function it_rejects_a_delete_whose_member_moves_while_it_waits(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The race needs PostgreSQL row locks across two connections.');
        }

        config()->set('database.connections.racer', config('database.connections.pgsql'));
        config()->set('database.connections.observer', config('database.connections.pgsql'));

        $racer = DB::connection('racer');
        $source = Section::factory()->connection('racer')->create();
        $destination = Section::factory()->connection('racer')->create();
        [$moved, $staying] = Asset::factory()->connection('racer')->for($source)->count(2)->create()->all();
        $rival = null;

        try {
            $racer->beginTransaction();
            $racer->table('sections')->where('id', $source->id)->lockForUpdate()->first();

            $rival = Process::env($this->databaseEnvironment())->start([
                PHP_BINARY,
                dirname(__DIR__, 3).'/tests/Fixtures/Support/Concurrency/delete.php',
                (string) $moved->id,
            ]);

            $this->awaitLockWait(1, $rival);

            $destination->orderedCollection('assets')->move($moved, Placement::first(), 0);

            $racer->commit();

            $result = $rival->wait();

            $this->assertSame('stale', $result->output(), $result->errorOutput());
            $this->assertSame(3, $source->orderedCollection('assets')->version());
            $this->assertSame(1, $destination->orderedCollection('assets')->version());
            $this->assertOrder([$staying->id], $source);
            $this->assertOrder([$moved->id], $destination);
        } finally {
            if ($racer->transactionLevel() > 0) {
                $racer->rollBack();
            }

            $rival?->wait();

            $racer->table('assets')->whereIn('section_id', [$source->id, $destination->id])->delete();
            $racer->table('sections')->whereIn('id', [$source->id, $destination->id])->delete();
        }
    }

    /**
     * @return array{Section, list<Asset>}
     */
    private function sectionWith(int $count): array
    {
        $section = Section::factory()->create();
        $assets = [];

        for ($i = 0; $i < $count; $i++) {
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
        $collection = $section->orderedCollection('assets');
        $version = $collection->version();
        $keys = $this->rankKeys($section);
        $thrown = null;

        try {
            $change();
        } catch (Throwable $thrown) {
        }

        $this->assertInstanceOf($exception, $thrown);
        $this->assertSame($version, $collection->version());
        $this->assertSame($keys, $this->rankKeys($section));
    }

    private function awaitLockWait(int $waiters, InvokedProcess ...$rivals): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            foreach ($rivals as $rival) {
                $this->assertTrue($rival->running(), $rival->errorOutput());
            }

            $waiting = DB::connection('observer')->scalar("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'");

            if ($waiting >= $waiters) {
                return;
            }

            usleep(10_000);
        }

        $this->fail('The rivals never waited on the row locks.');
    }

    private function startMove(Section $section, Asset $asset, int $expectedVersion): InvokedProcess
    {
        return Process::env($this->databaseEnvironment())->start([
            PHP_BINARY,
            dirname(__DIR__, 3).'/tests/Fixtures/Support/Concurrency/move.php',
            (string) $section->id,
            (string) $asset->id,
            (string) $expectedVersion,
        ]);
    }

    private function addScene(Tour $tour, Placement|null $placement = null): Scene
    {
        $scene = Scene::factory()->for($tour)->make();

        if ($placement !== null) {
            $scene->placeAt($placement);
        }

        $scene->save();

        return $scene;
    }

    /**
     * @param  list<Scene>  $expected
     */
    private function assertSceneOrder(array $expected, Tour $tour): void
    {
        $positions = Scene::query()
            ->whereBelongsTo($tour)
            ->get()
            ->mapWithKeys(fn (Scene $scene): array => [$scene->id => $scene->position])
            ->sort();

        $this->assertSame(array_map(fn (Scene $scene): string => $scene->id, $expected), $positions->keys()->all());
        $this->assertSame(range(1, count($expected)), $positions->values()->all());
    }

    /**
     * @return array<string, string>
     */
    private function databaseEnvironment(): array
    {
        return [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => config()->string('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => config()->string('database.connections.pgsql.database'),
            'DB_USERNAME' => config()->string('database.connections.pgsql.username'),
            'DB_PASSWORD' => config()->string('database.connections.pgsql.password'),
        ];
    }
}
