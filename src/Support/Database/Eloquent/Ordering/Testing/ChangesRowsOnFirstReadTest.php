<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Testing;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Ordering\Exceptions\NotAMember;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Sections\Section;
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
        $other = Asset::query()->create(['section_id' => $section->id]);

        $this->deleteOnFirstRead($asset);

        Section::query()->findOrFail($section->id);
        $this->assertNull($this->stored($asset)->deleted_at);

        Asset::query()->findOrFail($other->id);
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
    public function it_moves_the_row_to_the_target_on_the_first_read_of_its_table(): void
    {
        [$source, $target] = [Section::query()->create(), Section::query()->create()];
        $asset = Asset::query()->create(['section_id' => $source->id]);

        $this->moveOnFirstRead($asset->section(), $target);
        Asset::query()->find($asset->id);

        $this->assertSame($target->id, $this->stored($asset)->section_id);
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

    private function stored(Asset $asset): object
    {
        $row = DB::table('assets')->where('id', $asset->id)->first();
        $this->assertNotNull($row);

        return $row;
    }
}
