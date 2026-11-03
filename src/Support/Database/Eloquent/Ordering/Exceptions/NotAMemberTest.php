<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Placement;
use Tests\Fixtures\Support\Drawers\Drawer;
use Tests\Fixtures\Support\Drawers\DrawerRevision;
use Tests\Fixtures\Support\Notes\Note;
use Tests\Fixtures\Support\Playlists\Playlist;
use Tests\Fixtures\Support\Playlists\PlaylistRevision;
use Tests\Fixtures\Support\Scenes\Scene;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Tracks\Track;
use Tests\TestCase;

#[CoversClass(NotAMember::class)]
class NotAMemberTest extends TestCase
{
    #[Test]
    public function it_refuses_a_move_of_a_member_that_a_concurrent_delete_trashed_and_changes_nothing(): void
    {
        $this->racer();
        config()->set('database.connections.deleter', config('database.connections.pgsql'));
        $drawer = Drawer::on('racer')->create();
        [$moved, $staying] = [$this->addNote($drawer), $this->addNote($drawer)];
        $moving = Note::on('racer')->findOrFail($moved->id);
        $after = null;

        try {
            $this->deleteBeforeTheLock('notes', function () use ($moved, $drawer, &$after): void {
                Note::on('deleter')->findOrFail($moved->id)->delete();
                $after = $this->storedNotes($drawer);
            });

            $this->assertNotAMember(Note::class, $moved->id, fn (): mixed => $moving->move(Placement::last(), expected: null));

            $this->assertNotNull($after, 'The member was never trashed while the move ran.');
            $this->assertSame($after, $this->storedNotes($drawer));
            $this->assertSame([$staying->id], DB::connection('racer')->table('notes')->where('drawer_id', $drawer->id)->whereNull('deleted_at')->pluck('id')->all());
        } finally {
            DB::connection('racer')->table('notes')->where('drawer_id', $drawer->id)->delete();
            DB::connection('racer')->table('drawers')->where('id', $drawer->id)->delete();
        }
    }

    #[Test]
    public function it_refuses_a_move_of_a_member_that_a_concurrent_delete_hard_deleted_and_changes_nothing(): void
    {
        $this->racer();
        config()->set('database.connections.deleter', config('database.connections.pgsql'));
        $playlist = Playlist::on('racer')->create(['kind' => 'song']);
        $moved = Track::on('racer')->create(['playlist_id' => $playlist->id, 'kind' => 'song']);
        $staying = Track::on('racer')->create(['playlist_id' => $playlist->id, 'kind' => 'song']);
        $moving = Track::on('racer')->findOrFail($moved->id);
        $after = null;

        try {
            $this->deleteBeforeTheLock('tracks', function () use ($moved, $playlist, &$after): void {
                Track::on('deleter')->findOrFail($moved->id)->delete();
                $after = $this->storedTracks($playlist);
            });

            $this->assertNotAMember(Track::class, $moved->id, fn (): mixed => $moving->move(Placement::first(), expected: null));

            $this->assertNotNull($after, 'The member was never deleted while the move ran.');
            $this->assertSame($after, $this->storedTracks($playlist));
            $this->assertSame([$staying->id], DB::connection('racer')->table('tracks')->where('playlist_id', $playlist->id)->pluck('id')->all());
        } finally {
            DB::connection('racer')->table('tracks')->where('playlist_id', $playlist->id)->delete();
            DB::connection('racer')->table('playlists')->where('id', $playlist->id)->delete();
        }
    }

    #[Test]
    public function it_refuses_a_move_of_a_member_held_since_before_its_delete(): void
    {
        $drawer = Drawer::create();
        $note = $this->addNote($drawer);
        Note::query()->findOrFail($note->id)->delete();
        $before = $this->storedNotes($drawer);

        $this->assertNotAMember(Note::class, $note->id, fn (): mixed => $note->move(Placement::first(), expected: null));

        $this->assertSame($before, $this->storedNotes($drawer));
    }

    #[Test]
    public function it_refuses_a_move_with_an_expected_revision_of_a_member_trashed_since_the_revision_was_read(): void
    {
        $drawer = Drawer::create();
        [$note] = [$this->addNote($drawer), $this->addNote($drawer)];
        $expected = $drawer->concurrencyRevision(DrawerRevision::NotesOrder);
        Note::query()->findOrFail($note->id)->delete();
        $before = $this->storedNotes($drawer);

        $this->assertStale($drawer, DrawerRevision::NotesOrder, $expected, fn (): mixed => $note->move(Placement::last(), expected: $expected));
        $this->assertNotAMember(Note::class, $note->id, fn (): mixed => $note->move(Placement::last(), expected: $drawer->concurrencyRevision(DrawerRevision::NotesOrder)));

        $this->assertSame($before, $this->storedNotes($drawer));
    }

    #[Test]
    public function it_refuses_a_move_with_an_expected_revision_of_a_member_hard_deleted_since_the_revision_was_read(): void
    {
        $playlist = Playlist::create(['kind' => 'song']);
        $track = Track::create(['playlist_id' => $playlist->id, 'kind' => 'song']);
        Track::create(['playlist_id' => $playlist->id, 'kind' => 'song']);
        $expected = $playlist->concurrencyRevision(PlaylistRevision::TracksOrder);
        Track::query()->findOrFail($track->id)->delete();
        $before = $this->storedTracks($playlist);

        $this->assertStale($playlist, PlaylistRevision::TracksOrder, $expected, fn (): mixed => $track->move(Placement::last(), expected: $expected));
        $this->assertNotAMember(Track::class, $track->id, fn (): mixed => $track->move(Placement::last(), expected: $playlist->concurrencyRevision(PlaylistRevision::TracksOrder)));

        $this->assertSame($before, $this->storedTracks($playlist));
    }

    #[Test]
    public function it_refuses_a_move_in_with_an_expected_revision_of_a_member_trashed_since_the_revision_was_read(): void
    {
        $drawer = Drawer::create();
        [$note, $other] = [$this->addNote($drawer), $this->addNote($drawer)];
        $expected = $drawer->concurrencyRevision(DrawerRevision::NotesOrder);
        Note::query()->findOrFail($note->id)->delete();
        $before = $this->storedNotes($drawer);

        $this->assertStale($drawer, DrawerRevision::NotesOrder, $expected, fn (): mixed => $drawer->notes()->moveIn([$other, $note], expected: $expected));
        $this->assertNotAMember(Note::class, $note->id, fn (): mixed => $drawer->notes()->moveIn([$other, $note], expected: $drawer->concurrencyRevision(DrawerRevision::NotesOrder)));

        $this->assertSame($before, $this->storedNotes($drawer));
    }

    #[Test]
    public function it_refuses_a_move_in_with_an_expected_revision_of_a_member_hard_deleted_since_the_revision_was_read(): void
    {
        $playlist = Playlist::create(['kind' => 'song']);
        $track = Track::create(['playlist_id' => $playlist->id, 'kind' => 'song']);
        $other = Track::create(['playlist_id' => $playlist->id, 'kind' => 'song']);
        $expected = $playlist->concurrencyRevision(PlaylistRevision::TracksOrder);
        Track::query()->findOrFail($track->id)->delete();
        $before = $this->storedTracks($playlist);

        $this->assertStale($playlist, PlaylistRevision::TracksOrder, $expected, fn (): mixed => $playlist->tracks()->moveIn([$other, $track], expected: $expected));
        $this->assertNotAMember(Track::class, $track->id, fn (): mixed => $playlist->tracks()->moveIn([$other, $track], expected: $playlist->concurrencyRevision(PlaylistRevision::TracksOrder)));

        $this->assertSame($before, $this->storedTracks($playlist));
    }

    #[Test]
    public function it_refuses_a_move_of_a_member_whose_parent_row_is_gone(): void
    {
        $tour = Tour::create();
        $scene = $tour->scenes()->create();
        $tour->scenes()->create();
        DB::table('tours')->where('id', $tour->id)->delete();
        $before = $this->storedScenes($tour);

        $this->assertNotAMember(Scene::class, $scene->id, fn (): mixed => Scene::query()->findOrFail($scene->id)->move(Placement::last(), expected: null));

        $this->assertSame($before, $this->storedScenes($tour));
    }

    #[Test]
    public function it_refuses_a_move_of_a_member_of_a_held_parent_whose_row_is_gone(): void
    {
        $tour = Tour::create();
        $tour->scenes()->create();
        $scene = $tour->scenes()->create();
        $scene->setRelation('sceneable', $tour);
        DB::table('tours')->where('id', $tour->id)->delete();
        $before = $this->storedScenes($tour);

        $this->assertNotAMember(Scene::class, $scene->id, fn (): mixed => $scene->move(Placement::first(), expected: null));

        $this->assertSame($before, $this->storedScenes($tour));
    }

    private function addNote(Drawer $drawer): Note
    {
        $note = (new Note)->setConnection($drawer->getConnectionName());
        $note->drawer()->associate($drawer);
        $note->save();

        return $note;
    }

    private function deleteBeforeTheLock(string $table, callable $delete): void
    {
        $deleted = false;

        DB::listen(function (QueryExecuted $query) use (&$deleted, $table, $delete): void {
            if ($deleted || $query->connectionName !== 'racer' || ! str_contains($query->sql, sprintf('from "%s"', $table)) || ! str_starts_with($query->sql, 'select "')) {
                return;
            }

            $deleted = true;
            $delete();
        });
    }

    /**
     * @return list<mixed>
     */
    private function storedNotes(Drawer $drawer): array
    {
        return [
            DB::connection($drawer->getConnectionName())->table('notes')->where('drawer_id', $drawer->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            Drawer::on($drawer->getConnectionName())->findOrFail($drawer->id)->concurrencyRevision(DrawerRevision::NotesOrder),
        ];
    }

    /**
     * @return list<mixed>
     */
    private function storedTracks(Playlist $playlist): array
    {
        return [
            DB::connection($playlist->getConnectionName())->table('tracks')->where('playlist_id', $playlist->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            Playlist::on($playlist->getConnectionName())->findOrFail($playlist->id)->concurrencyRevision(PlaylistRevision::TracksOrder),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedScenes(Tour $tour): array
    {
        return DB::table('scenes')->where('sceneable_id', $tour->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    private function assertStale(Model $parent, NamesConcurrencyRevision $revision, string $expected, callable $move): void
    {
        try {
            $move();
            $this->fail('A move with a stale expected revision succeeded.');
        } catch (StaleConcurrencyRevision $exception) {
            $this->assertSame(sprintf('The [%s] concurrency revision of [%s:%s] is no longer [%s].', $revision->value, $parent->getMorphClass(), $parent->getKey(), $expected), $exception->getMessage());
        }
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function assertNotAMember(string $class, int|string $id, callable $move): void
    {
        try {
            $move();
            $this->fail('A move of a member that its order no longer holds succeeded.');
        } catch (NotAMember $exception) {
            $this->assertInstanceOf(InvalidArgumentException::class, $exception);
            $this->assertSame($class, $exception->class);
            $this->assertSame([$id], $exception->ids);
            $this->assertSame(sprintf('No order holds [%s] as a current member: [%s].', $class, $id), $exception->getMessage());
        }
    }
}
