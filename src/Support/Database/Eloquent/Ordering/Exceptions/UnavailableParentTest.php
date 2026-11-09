<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Support\Database\Eloquent\Ordering\Placement;
use Tests\Fixtures\Support\Binders\Binder;
use Tests\Fixtures\Support\Conferences\Conference;
use Tests\Fixtures\Support\Drawers\Drawer;
use Tests\Fixtures\Support\Drawers\DrawerRevision;
use Tests\Fixtures\Support\Notes\Note;
use Tests\Fixtures\Support\Pages\Page;
use Tests\Fixtures\Support\Panels\Panel;
use Tests\Fixtures\Support\Scenes\Scene;
use Tests\Fixtures\Support\Shelves\Shelf;
use Tests\Fixtures\Support\Speakers\Speaker;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\TestCase;

#[CoversClass(UnavailableParent::class)]
class UnavailableParentTest extends TestCase
{
    #[Test]
    public function it_refuses_a_create_in_a_trashed_parent_after_it_locks_the_parent(): void
    {
        $drawer = Drawer::create();
        $this->addNote($drawer);
        $drawer->delete();
        $before = $this->stored($drawer);
        DB::enableQueryLog();

        $this->assertUnavailable($drawer, ParentUnavailability::Trashed, fn (): Note => $this->addNote($drawer));

        $this->assertSame($before, $this->stored($drawer));
        $this->assertTrue($this->lockedBeforeRefusal($drawer));
    }

    #[Test]
    public function it_names_a_parent_that_the_required_morph_map_omits_by_its_class(): void
    {
        Relation::requireMorphMap();
        $drawer = Drawer::create();
        $drawer->delete();

        try {
            $this->addNote($drawer);
            $this->fail('A write placed a member in an unavailable parent.');
        } catch (UnavailableParent $exception) {
            $this->assertSame(sprintf('[%s:%s] is trashed, so no write can place a member in its order.', Drawer::class, $drawer->getKey()), $exception->getMessage());
        }
    }

    #[Test]
    public function it_refuses_a_create_in_a_held_parent_whose_row_is_gone(): void
    {
        $drawer = Drawer::create();
        DB::table('drawers')->where('id', $drawer->id)->delete();

        $this->assertUnavailable($drawer, ParentUnavailability::Missing, fn (): Note => $this->addNote($drawer));

        $this->assertSame(0, DB::table('notes')->count());
    }

    #[Test]
    public function it_refuses_a_save_that_moves_a_member_into_a_trashed_parent(): void
    {
        $source = Drawer::create();
        $note = $this->addNote($source);
        $trashed = Drawer::create();
        $trashed->delete();
        $before = [$this->stored($source), $this->stored($trashed)];

        $note->drawer()->associate($trashed);

        $this->assertUnavailable($trashed, ParentUnavailability::Trashed, fn (): bool => $note->save());

        $this->assertSame($before, [$this->stored($source), $this->stored($trashed)]);
    }

    #[Test]
    public function it_refuses_a_move_into_a_trashed_parent(): void
    {
        $source = Drawer::create();
        $note = $this->addNote($source);
        $trashed = Drawer::create();
        $trashed->delete();
        $before = [$this->stored($source), $this->stored($trashed)];

        $this->assertUnavailable($trashed, ParentUnavailability::Trashed, fn (): mixed => $note->move(Placement::first()->in($trashed), expected: null));

        $this->assertSame($before, [$this->stored($source), $this->stored($trashed)]);
    }

    #[Test]
    public function it_refuses_a_restore_into_a_trashed_parent(): void
    {
        $drawer = Drawer::create();
        $note = $this->addNote($drawer);
        $note->delete();
        $drawer->delete();
        $before = $this->stored($drawer);

        $this->assertUnavailable($drawer, ParentUnavailability::Trashed, fn (): mixed => self::restore($note));

        $this->assertSame($before, $this->stored($drawer));
    }

    #[Test]
    public function it_refuses_a_create_in_a_parent_whose_row_was_gone_before_the_create_read_it(): void
    {
        $drawer = Drawer::create();
        DB::table('drawers')->where('id', $drawer->id)->delete();

        $this->assertUnavailable($drawer, ParentUnavailability::Missing, fn (): Note => Note::create(['drawer_id' => $drawer->id]));

        $this->assertSame(0, DB::table('notes')->count());
    }

    #[Test]
    public function it_refuses_a_save_that_moves_a_member_into_a_parent_whose_row_is_gone(): void
    {
        $source = Drawer::create();
        $note = $this->addNote($source);
        $gone = Drawer::create();
        DB::table('drawers')->where('id', $gone->id)->delete();
        $before = $this->stored($source);

        $note->drawer_id = $gone->id;

        $this->assertUnavailable($gone, ParentUnavailability::Missing, fn (): bool => $note->save());

        $this->assertSame($before, $this->stored($source));
    }

    #[Test]
    public function it_refuses_a_move_into_a_held_parent_whose_row_is_gone(): void
    {
        $source = Drawer::create();
        $note = $this->addNote($source);
        $gone = Drawer::create();
        DB::table('drawers')->where('id', $gone->id)->delete();
        $before = $this->stored($source);

        $this->assertUnavailable($gone, ParentUnavailability::Missing, fn (): mixed => $note->move(Placement::first()->in($gone), expected: null));

        $this->assertSame($before, $this->stored($source));
    }

    #[Test]
    public function it_refuses_a_restore_into_a_parent_whose_row_is_gone(): void
    {
        $tour = Tour::create();
        $scene = $tour->scenes()->create();
        $scene->delete();
        DB::table('tours')->where('id', $tour->id)->delete();

        $this->assertUnavailable($tour, ParentUnavailability::Missing, fn (): mixed => self::restore(Scene::withTrashed()->findOrFail($scene->id)));

        $this->assertNotNull(DB::table('scenes')->where('id', $scene->id)->value('deleted_at'));
    }

    #[Test]
    public function it_refuses_a_create_in_a_filtered_parent_whose_row_is_gone_while_missing_attributes_are_prevented(): void
    {
        $gone = Binder::create(['shelf_id' => Shelf::create()->id]);
        DB::table('binders')->where('id', $gone->id)->delete();
        $preventing = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();

        try {
            $this->assertUnavailable($gone, ParentUnavailability::Missing, fn (): Page => Page::create(['binder_id' => $gone->id]));
        } finally {
            Model::preventAccessingMissingAttributes($preventing);
        }

        $this->assertSame(0, DB::table('pages')->count());
    }

    #[Test]
    public function it_refuses_a_save_that_moves_a_member_into_a_filtered_parent_whose_row_is_gone_while_missing_attributes_are_prevented(): void
    {
        $shelf = Shelf::create();
        $source = Binder::create(['shelf_id' => $shelf->id]);
        $gone = Binder::create(['shelf_id' => $shelf->id]);
        $page = Page::create(['binder_id' => $source->id]);
        DB::table('binders')->where('id', $gone->id)->delete();
        $before = DB::table('pages')->get()->map(fn (object $row): array => (array) $row)->all();
        $preventing = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();

        try {
            $reloaded = Page::query()->findOrFail($page->id);
            $reloaded->binder_id = $gone->id;

            $this->assertUnavailable($gone, ParentUnavailability::Missing, fn (): bool => $reloaded->save());
        } finally {
            Model::preventAccessingMissingAttributes($preventing);
        }

        $this->assertSame($before, DB::table('pages')->get()->map(fn (object $row): array => (array) $row)->all());
    }

    #[Test]
    public function it_refuses_an_attach_to_a_trashed_pivot_parent(): void
    {
        $conference = Conference::create();
        $speaker = Speaker::query()->create(['panel_id' => Panel::create()->id]);
        $conference->delete();

        $this->assertUnavailable($conference, ParentUnavailability::Trashed, function () use ($conference, $speaker): void {
            $conference->speakers()->attach($speaker->id);
        });

        $this->assertSame(0, DB::table('conference_speaker')->count());
    }

    #[Test]
    public function it_refuses_a_create_that_waited_on_the_lock_of_a_parent_that_a_concurrent_delete_trashed(): void
    {
        $racer = $this->racer();
        $drawer = Drawer::on('racer')->create();
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('drawers')->where('id', $drawer->id)->lockForUpdate()->first();

            $rivals[] = $this->startRival('trash-parent.php', (string) $drawer->id);
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startRival('create.php', (string) $drawer->id);
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $outputs = array_map(fn (InvokedProcess $rival): string => $rival->wait()->output(), $rivals);

            $this->assertSame(['trashed', 'unavailable:trashed'], $outputs, implode(PHP_EOL, array_map(fn (InvokedProcess $rival): string => $rival->errorOutput(), $rivals)));
            $this->assertSame(0, $racer->table('notes')->where('drawer_id', $drawer->id)->count());
        } finally {
            if ($racer->transactionLevel() > 0) {
                $racer->rollBack();
            }

            foreach ($rivals as $rival) {
                $rival->wait();
            }

            $racer->table('notes')->where('drawer_id', $drawer->id)->delete();
            $racer->table('drawers')->where('id', $drawer->id)->delete();
        }
    }

    private static function restore(Model $note): mixed
    {
        assert(method_exists($note, 'restore'));

        return $note->restore();
    }

    private function addNote(Drawer $drawer): Note
    {
        $note = new Note;
        $note->drawer()->associate($drawer);
        $note->save();

        return $note;
    }

    /**
     * @return list<mixed>
     */
    private function stored(Drawer $drawer): array
    {
        return [
            DB::table('notes')->where('drawer_id', $drawer->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            Drawer::withTrashed()->findOrFail($drawer->id)->concurrencyRevision(DrawerRevision::NotesOrder),
        ];
    }

    private function assertUnavailable(Model $parent, ParentUnavailability $reason, Closure $write): void
    {
        try {
            $write();
            $this->fail('A write placed a member in an unavailable parent.');
        } catch (UnavailableParent $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertSame($reason, $exception->reason);
            $this->assertSame($parent->getKey(), $exception->parent->getKey());
            $this->assertSame(sprintf('[%s:%s] is %s, so no write can place a member in its order.', $parent->getMorphClass(), $parent->getKey(), $reason->value), $exception->getMessage());
        }
    }

    private function lockedBeforeRefusal(Drawer $drawer): bool
    {
        return array_any(
            DB::getQueryLog(),
            fn (array $statement): bool => str_contains($statement['query'], 'from "drawers"') && str_ends_with($statement['query'], 'for no key update') && $statement['bindings'] === [$drawer->id],
        );
    }
}
