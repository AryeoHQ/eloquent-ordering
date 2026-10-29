<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Boards\Board;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Photos\Photo;
use Tests\Fixtures\Support\Playlists\Playlist;
use Tests\Fixtures\Support\Scenes\Scene;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Shapes\BelongsToManyShape;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\Fixtures\Support\Shapes\UuidMorphManyShape;
use Tests\Fixtures\Support\Tracks\Track;
use Tests\Fixtures\Support\Videos\Video;
use Tests\TestCase;

#[CoversClass(OrderedCollection::class)]
class OrderedCollectionTest extends TestCase
{
    /**
     * @return iterable<string, array{Shape}>
     */
    public static function shapes(): iterable
    {
        return Shape::all();
    }

    /**
     * @return iterable<string, array{PivotShape}>
     */
    public static function pivots(): iterable
    {
        return PivotShape::pivots();
    }

    #[DataProvider('shapes')]
    #[Test]
    public function it_loads_the_position_the_accessor_counts_for_each_member_in_one_query_after_it_loads_the_parent(Shape $shape): void
    {
        [$parent, $members] = $shape->with(3);
        $hidden = $shape->add($parent, hidden: true);
        $members[2]->move(Placement::first(), expected: null);
        $trashed = $shape->add($parent);
        $trashed->delete();
        $items = new OrderedCollection(array_map(fn (Model $member): Model&Ordered => $shape->find($this->key($member)), [...$members, $hidden, $trashed]));
        $counted = array_map(fn (Model $item): mixed => $shape->find($this->key($item))->getAttribute('position'), $items->all());
        DB::enableQueryLog();

        $items->loadPositions();

        $this->assertCount(2, DB::getQueryLog());
        $this->assertStringContainsString(sprintf('from "%s"', $shape->parentTable()), DB::getQueryLog()[0]['query']);
        $this->assertSame([2, 3, 1, 4, null], $counted);
        $this->assertSame($counted, array_map($this->loaded(...), $items->all()));
    }

    #[Test]
    public function it_loads_positions_in_one_query_per_parent_class_and_one_per_item_class_and_relation(): void
    {
        $items = [];

        foreach (Shape::all() as [$shape]) {
            if ($shape instanceof UuidMorphManyShape) {
                continue;
            }

            foreach ([$shape->with(2), $shape->with(2)] as [, $members]) {
                foreach ($members as $member) {
                    $items[] = $shape->find($this->key($member));
                }
            }
        }

        $scene = new Scene;
        $scene->sceneable()->associate(Listing::create());
        $scene->save();
        $video = new Video;
        $video->section()->associate(Section::create());
        $video->save();
        $items = new OrderedCollection([...$items, Scene::query()->findOrFail($scene->id), Video::query()->findOrFail($video->id)]);
        $items = $items->shuffle();
        $counted = $items->map(fn (Model $item): mixed => $item->newQueryWithoutScopes()->findOrFail($item->getKey())->getAttribute('position'))->all();
        DB::enableQueryLog();

        $items->loadPositions();

        $this->assertCount(9, DB::getQueryLog());
        $this->assertSame($counted, $items->map($this->loaded(...))->all());
    }

    #[Test]
    public function it_loads_each_position_through_the_relation_of_the_parent_that_holds_the_item(): void
    {
        $tracks = [...$this->playlist('audio', ['audio', 'video', 'audio']), ...$this->playlist('video', ['video', 'audio', 'video'])];
        $items = new OrderedCollection(array_map(fn (Track $track): Track => Track::query()->findOrFail($track->id), $tracks));
        $counted = array_map(fn (Track $track): mixed => Track::query()->findOrFail($track->id)->position, $tracks);
        DB::enableQueryLog();

        $items->loadPositions();

        $this->assertCount(2, DB::getQueryLog());
        $this->assertSame([1, null, 2, 1, null, 2], $counted);
        $this->assertSame($counted, array_map($this->loaded(...), $items->all()));
    }

    #[Test]
    public function it_loads_no_parent_that_an_item_already_holds(): void
    {
        $tracks = [...$this->playlist('audio', ['audio', 'video']), ...$this->playlist('video', ['audio', 'video'])];
        [$listing] = (new BelongsToManyShape)->with(2);
        $pivots = (new BelongsToManyShape)->orderedRelation($listing)->query()->get()->map(fn (Model $customer): mixed => $customer->getRelation('pivot'))->all();
        $items = new OrderedCollection([...Track::query()->with('playlist')->whereKey(array_map(fn (Track $track): int => $track->id, $tracks))->orderBy('id')->get()->all(), ...$pivots]);
        DB::enableQueryLog();

        $items->loadPositions();

        $this->assertCount(2, DB::getQueryLog());
        $this->assertSame([1, null, null, 1, 1, 2], array_map($this->loaded(...), $items->all()));
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_names_the_ways_to_load_the_key_of_a_pivot_it_cannot_match(PivotShape $shape): void
    {
        [$parent] = $shape->with(2);
        $pivots = $shape->customers($parent)->get()->map(fn (Model $customer): mixed => $customer->getRelation('pivot'))->all();

        try {
            (new OrderedCollection($pivots))->loadPositions();
            $this->fail('A pivot without its key received a position.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString(sprintf("Load a pivot through [%s::query()], or through a relation that declares withPivot('id').", OrderedRelation::class), $exception->getMessage());
        }
    }

    #[Test]
    public function it_loads_no_position_for_an_item_that_has_none(): void
    {
        $section = Section::create();
        $trashed = $this->addAsset($section);
        $trashed->delete();
        $keyless = $this->addAsset($section);
        DB::table('assets')->where('id', $keyless->id)->update([OrderedRelation::RANK_KEY => null]);
        $orphan = new Photo;
        $orphan->board()->associate(Board::create());
        $orphan->save();
        DB::table('photos')->where('id', $orphan->id)->update(['board_id' => null]);
        $unsaved = new Asset;
        $items = new OrderedCollection([Asset::query()->withTrashed()->findOrFail($trashed->id), Asset::query()->findOrFail($keyless->id), Photo::query()->findOrFail($orphan->id), $unsaved]);
        DB::enableQueryLog();

        $items->loadPositions();

        $this->assertCount(2, DB::getQueryLog());
        $this->assertSame([null, null, null, 'missing'], $items->map($this->loaded(...))->all());
    }

    #[Test]
    public function it_runs_no_query_for_an_empty_collection(): void
    {
        DB::enableQueryLog();

        (new OrderedCollection)->loadPositions();

        $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function it_collects_the_results_of_a_query_of_items_that_declare_it(): void
    {
        $section = Section::create();
        $first = $this->addAsset($section);
        $second = $this->addAsset($section);

        $assets = Asset::query()->whereKey([$second->id, $first->id])->orderByDesc('id')->get()->loadPositions();

        $this->assertInstanceOf(OrderedCollection::class, $assets);
        $this->assertSame([$second->id => 2, $first->id => 1], $assets->mapWithKeys(fn (Asset $asset): array => [$asset->id => $asset->getAttributes()[OrderedRelation::POSITION]])->all());
    }

    #[Test]
    public function it_rejects_a_model_that_is_not_ordered(): void
    {
        try {
            (new OrderedCollection([Section::create()]))->loadPositions();
            $this->fail('A model that is not ordered received a position.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('is not ordered, so it has no position.', $exception->getMessage());
        }
    }

    private function addAsset(Section $section): Asset
    {
        $asset = new Asset;
        $asset->section()->associate($section);
        $asset->save();

        return $asset;
    }

    /**
     * @param  list<string>  $kinds
     * @return list<Track>
     */
    private function playlist(string $kind, array $kinds): array
    {
        $playlist = Playlist::create(['kind' => $kind]);

        return array_map(function (string $kind) use ($playlist): Track {
            $track = new Track(['kind' => $kind]);
            $track->playlist()->associate($playlist);
            $track->save();

            return $track;
        }, $kinds);
    }

    private function loaded(Model $item): mixed
    {
        $attributes = $item->getAttributes();

        return array_key_exists(OrderedRelation::POSITION, $attributes) ? $attributes[OrderedRelation::POSITION] : 'missing';
    }

    private function key(Model $model): int|string
    {
        $key = $model->getKey();

        $this->assertTrue(is_int($key) || is_string($key));

        return $key;
    }
}
