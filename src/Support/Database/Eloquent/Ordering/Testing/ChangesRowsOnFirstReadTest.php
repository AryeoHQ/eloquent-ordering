<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Ordering\Exceptions\NotAMember;
use Support\Database\Eloquent\Ordering\Exceptions\ParentUnavailability;
use Support\Database\Eloquent\Ordering\Exceptions\UnavailableParent;
use Support\Database\Eloquent\Ordering\Keys\FractionalIndex;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Customers\Customerable;
use Tests\Fixtures\Support\Drawers\Drawer;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Notes\Note;
use Tests\Fixtures\Support\Scenes\Scene;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Videos\Video;
use Tests\TestCase;

#[CoversTrait(ChangesRowsOnFirstRead::class)]
class ChangesRowsOnFirstReadTest extends TestCase
{
    use ChangesRowsOnFirstRead;

    #[Test]
    public function it_trashes_the_row_on_the_first_read_of_its_table(): void
    {
        $section = Section::query()->create();
        $asset = Asset::query()->create(['section_id' => $section->id]);

        $this->deleteOnFirstRead($asset);

        Section::query()->findOrFail($section->id);
        $this->assertFalse(Asset::query()->findOrFail($asset->id)->trashed());

        $stored = $this->stored($asset);
        $this->assertNotNull($stored->deleted_at);
        $this->assertNull($stored->rank_key);
    }

    #[Test]
    public function it_deletes_the_row_of_a_model_that_does_not_soft_delete(): void
    {
        $section = Section::query()->create();
        $video = Video::query()->create(['section_id' => $section->id]);

        $this->deleteOnFirstRead($video);
        Video::query()->find($video->id);

        $this->assertFalse(DB::table('videos')->where('id', $video->id)->exists());
    }

    #[Test]
    public function it_trashes_an_unordered_parent_so_that_a_create_into_it_is_refused(): void
    {
        $created = Drawer::query()->create();

        $this->deleteOnFirstRead($created);
        $drawer = Drawer::query()->findOrFail($created->id);

        try {
            $note = new Note;
            $note->drawer()->associate($drawer);
            $note->save();
            $this->fail('The create placed a member in a trashed parent.');
        } catch (UnavailableParent $refused) {
            $this->assertSame(ParentUnavailability::Trashed, $refused->reason);
        }
    }

    #[Test]
    public function it_changes_the_row_only_once(): void
    {
        $section = Section::query()->create();
        $asset = Asset::query()->create(['section_id' => $section->id]);

        $this->deleteOnFirstRead($asset);
        Asset::query()->find($asset->id);
        DB::table('assets')->where('id', $asset->id)->update(['deleted_at' => null]);

        Asset::query()->find($asset->id);

        $this->assertNull($this->stored($asset)->deleted_at);
    }

    #[Test]
    public function it_changes_the_row_on_a_read_that_joins_its_table(): void
    {
        $tour = Tour::query()->create();
        $tour->customers()->attach(Customer::query()->create()->id);
        $pivot = Customerable::query()->sole();

        $this->deleteOnFirstRead($pivot);
        DB::table('customers')->join('customerables', 'customerables.customer_id', '=', 'customers.id')->get();

        $this->assertNotNull($this->stored($pivot)->deleted_at);
    }

    #[Test]
    public function it_changes_the_row_on_a_read_that_nests_its_table_in_a_subquery(): void
    {
        $section = Section::query()->create();
        $asset = Asset::query()->create(['section_id' => $section->id]);

        $this->deleteOnFirstRead($asset);
        DB::query()->fromSub(DB::table('assets')->select('id'), 'nested')->get();

        $this->assertNotNull($this->stored($asset)->deleted_at);
    }

    #[Test]
    public function it_ignores_a_read_of_a_table_whose_name_extends_the_rows_table(): void
    {
        Schema::create('assets_archive', static function (Blueprint $table): void {
            $table->id();
        });
        $section = Section::query()->create();
        $asset = Asset::query()->create(['section_id' => $section->id]);

        $this->deleteOnFirstRead($asset);
        DB::table('assets_archive')->get();

        $this->assertFalse(Asset::query()->findOrFail($asset->id)->trashed());
        $this->assertNotNull($this->stored($asset)->deleted_at);
    }

    #[Test]
    public function it_refuses_a_model_that_has_no_key(): void
    {
        $tour = Tour::query()->create();
        $tour->customers()->attach(Customer::query()->create()->id);
        $pivot = $tour->customers()->firstOrFail()->getRelation('pivot');
        $this->assertInstanceOf(Model::class, $pivot);

        $this->expectException(LogicException::class);

        $this->deleteOnFirstRead($pivot);
    }

    #[Test]
    public function it_moves_the_row_to_the_target_on_the_first_read_of_its_table(): void
    {
        [$source, $target] = [Section::query()->create(), Section::query()->create()];
        $asset = Asset::query()->create(['section_id' => $source->id]);

        $this->moveOnFirstRead($asset->section(), $target);
        Asset::query()->find($asset->id);

        $this->assertSame($target->id, $this->stored($asset)->section_id);
    }

    #[Test]
    public function it_moves_the_row_after_the_last_member_of_the_target(): void
    {
        [$source, $target] = [Section::query()->create(), Section::query()->create()];
        $asset = Asset::query()->create(['section_id' => $source->id]);
        $member = Asset::query()->create(['section_id' => $target->id]);

        $this->moveOnFirstRead($asset->section(), $target);
        Asset::query()->find($asset->id);

        $stored = $this->stored($asset);
        $this->assertSame($target->id, $stored->section_id);
        $this->assertSame(FractionalIndex::keyBetween($this->stored($member)->rank_key, null), $stored->rank_key);
    }

    #[Test]
    public function it_moves_a_morph_child_to_a_parent_of_another_type(): void
    {
        [$tour, $listing] = [Tour::query()->create(), Listing::query()->create()];
        $scene = $tour->scenes()->create();
        $listing->scenes()->create();

        $this->moveOnFirstRead($scene->sceneable(), $listing);
        Scene::query()->find($scene->id);

        $stored = $this->stored($scene);
        $this->assertSame($listing->getMorphClass(), $stored->sceneable_type);
        $this->assertSame($listing->id, $stored->sceneable_id);
    }

    #[Test]
    public function it_moves_two_rows_into_one_target_one_after_the_other(): void
    {
        [$source, $target] = [Section::query()->create(), Section::query()->create()];
        $first = Asset::query()->create(['section_id' => $source->id]);
        $second = Asset::query()->create(['section_id' => $source->id]);

        $this->moveOnFirstRead($first->section(), $target);
        $this->moveOnFirstRead($second->section(), $target);
        Asset::query()->find($first->id);
        Asset::query()->find($second->id);

        [$moved, $waited] = [$this->stored($first), $this->stored($second)];
        $this->assertSame([$target->id, $target->id], [$moved->section_id, $waited->section_id]);
        $this->assertSame(FractionalIndex::keyBetween($moved->rank_key, null), $waited->rank_key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readsOfTheRowsTable(): iterable
    {
        yield 'uppercase keywords' => ['SELECT * FROM "assets"'];
        yield 'a newline before the table' => ["select *\nfrom\n\"assets\""];
        yield 'a tab before the table' => ["select * from\t\"assets\""];
        yield 'an unquoted table' => ['select * from assets'];
        yield 'an unquoted table in a join' => ['select * from "sections" inner join assets on assets.section_id = sections.id'];
        yield 'a leading with' => ['with listed as (select id from "assets") select * from listed'];
    }

    #[DataProvider('readsOfTheRowsTable')]
    #[Test]
    public function it_changes_the_row_on_a_read_written_by_hand(string $sql): void
    {
        $section = Section::query()->create();
        $asset = Asset::query()->create(['section_id' => $section->id]);

        $this->deleteOnFirstRead($asset);
        DB::select($sql);

        $this->assertNotNull($this->stored($asset)->deleted_at);
    }

    #[Test]
    public function it_ignores_a_read_of_an_unquoted_table_whose_name_extends_the_rows_table(): void
    {
        Schema::create('assets_archive', static function (Blueprint $table): void {
            $table->id();
        });
        $section = Section::query()->create();
        $asset = Asset::query()->create(['section_id' => $section->id]);

        $this->deleteOnFirstRead($asset);
        DB::select('select * from assets_archive');

        $this->assertFalse(Asset::query()->findOrFail($asset->id)->trashed());
    }

    #[Test]
    public function it_changes_the_row_on_a_string_literal_that_names_its_table(): void
    {
        $section = Section::query()->create();
        $asset = Asset::query()->create(['section_id' => $section->id]);

        $this->deleteOnFirstRead($asset);
        DB::select('select \'from "assets"\' as text');

        $this->assertNotNull($this->stored($asset)->deleted_at);
    }

    #[Test]
    public function it_refuses_a_target_that_has_no_key(): void
    {
        $section = Section::query()->create();
        $asset = Asset::query()->create(['section_id' => $section->id]);

        $this->expectException(LogicException::class);

        $this->moveOnFirstRead($asset->section(), new Section);
    }

    #[Test]
    public function a_move_in_reads_the_parent_that_a_forged_move_gave_the_member(): void
    {
        [$read, $stored, $destination] = [Section::query()->create(), Section::query()->create(), Section::query()->create()];
        $asset = Asset::query()->create(['section_id' => $read->id]);
        $moving = Asset::query()->findOrFail($asset->id);

        $this->moveOnFirstRead($asset->section(), $stored);
        $change = $destination->assets()->moveIn([$moving], expected: null);

        $this->assertSame($stored->id, $change->source?->parent->getKey());
        $this->assertSame($destination->id, $this->stored($asset)->section_id);
    }

    #[Test]
    public function a_move_in_refuses_a_member_that_a_forged_delete_trashed(): void
    {
        [$source, $destination] = [Section::query()->create(), Section::query()->create()];
        $asset = Asset::query()->create(['section_id' => $source->id]);
        $moving = Asset::query()->findOrFail($asset->id);

        $this->deleteOnFirstRead($asset);

        try {
            $destination->assets()->moveIn([$moving], expected: null);
            $this->fail('The move in moved a trashed member.');
        } catch (NotAMember $refused) {
            $this->assertSame([$asset->id], $refused->ids);
        }
    }

    private function stored(Model $model): object
    {
        $row = DB::table($model->getTable())->where($model->getKeyName(), $model->getKey())->first();
        $this->assertNotNull($row);

        return $row;
    }
}
