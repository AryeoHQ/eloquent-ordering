<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Contracts\LocksParentsFirst;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Tests\Fixtures\Support\Conferences\Conference;
use Tests\Fixtures\Support\Panels\Panel;
use Tests\Fixtures\Support\Speakers\Speaker;
use Tests\TestCase;

#[CoversTrait(LocksOrderedParentsFirst::class)]
#[DefineEnvironment('orderTheSpeakersOfEveryConference')]
class LocksOrderedParentsFirstTest extends TestCase
{
    #[Test]
    public function it_loads_a_model_that_is_ordered_and_the_related_side_of_an_ordered_pivot(): void
    {
        $speaker = new Speaker;

        $this->assertInstanceOf(Ordered::class, $speaker);
        $this->assertInstanceOf(LocksParentsFirst::class, $speaker);
    }

    #[Test]
    public function it_names_the_parents_that_order_the_model_then_the_parents_that_its_pivots_reach_each_once_without_a_lock(): void
    {
        [$panel, $destination] = [Panel::create(), Panel::create()];
        $speaker = $panel->speakers()->create();
        [$member, $former] = [Conference::create(), Conference::create()];
        $member->speakers()->attach($speaker);
        $former->speakers()->attach($speaker);
        $former->speakers()->detach($speaker);
        $loaded = Speaker::query()->findOrFail($speaker->id);
        $loaded->panel_id = $destination->id;
        DB::enableQueryLog();

        $parents = $loaded->parentsToLockFirst();

        $this->assertSame(
            [[Panel::class, $panel->id], [Panel::class, $destination->id], [Conference::class, $member->id], [Conference::class, $former->id]],
            array_map(fn (Model $parent): array => [$parent::class, $parent->getKey()], $parents),
        );
        $this->assertSame([], array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $query): bool => preg_match('/\bfor (no key )?(update|share)\b/', $query) === 1));
    }

    #[Test]
    public function it_raises_both_panels_and_no_conference_when_a_save_moves_the_model_to_another_panel(): void
    {
        [$panel, $destination] = [Panel::create(), Panel::create()];
        $speaker = $panel->speakers()->create();
        $conference = Conference::create();
        $conference->speakers()->attach($speaker);
        $before = $this->revisions($panel, $destination, $conference);

        $this->runDeferredChecks(fn (): bool => $speaker->update(['panel_id' => $destination->id]));

        $this->assertSame([$before[0] + 1, $before[1] + 1, $before[2]], $this->revisions($panel, $destination, $conference));
        $this->assertSame(1, $speaker->refresh()->position);
    }

    #[Test]
    public function it_raises_the_panel_and_every_conference_when_the_model_is_soft_deleted_or_restored(): void
    {
        $panel = Panel::create();
        $speaker = $panel->speakers()->create();
        [$first, $second] = [Conference::create(), Conference::create()];
        $first->speakers()->attach($speaker);
        $second->speakers()->attach($speaker);
        $before = $this->revisions($panel, $first, $second);

        $this->runDeferredChecks(fn (): mixed => $speaker->delete());

        $this->assertSame(array_map(fn (int $revision): int => $revision + 1, $before), $this->revisions($panel, $first, $second));
        $this->assertSame([], $first->speakers()->pluck('speakers.id')->all());

        $this->runDeferredChecks(fn (): mixed => self::restore($speaker));

        $this->assertSame(array_map(fn (int $revision): int => $revision + 2, $before), $this->revisions($panel, $first, $second));
        $this->assertSame([$speaker->id], $first->speakers()->pluck('speakers.id')->all());
        $this->assertSame(1, $speaker->refresh()->position);
    }

    #[Test]
    public function it_locks_the_conference_before_the_panel_when_a_soft_delete_or_a_restore_reaches_both(): void
    {
        $panel = Panel::create();
        $speaker = $panel->speakers()->create();
        $conference = Conference::create();
        $conference->speakers()->attach($speaker);
        DB::enableQueryLog();

        $this->runDeferredChecks(fn (): mixed => $speaker->delete());
        $deleted = $this->lockedParentTables();
        DB::flushQueryLog();

        $this->runDeferredChecks(fn (): mixed => self::restore($speaker));

        $this->assertSame([['conferences', 'panels'], ['conferences', 'panels']], [$deleted, $this->lockedParentTables()]);
    }

    #[Test]
    public function it_finishes_soft_deletes_of_two_speakers_that_share_their_parents_without_a_deadlock_when_one_locks_the_sorted_parents_first(): void
    {
        $racer = $this->racer();
        $panel = Panel::on('racer')->create();
        [$first, $second] = [$panel->speakers()->create(), $panel->speakers()->create()];
        $conference = Conference::on('racer')->create();
        $conference->speakers()->attach($first);
        $conference->speakers()->attach($second);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('conferences')->where('id', $conference->id)->lockForUpdate()->first();

            $rivals[] = $this->startRival('locked-delete.php', Speaker::class, (string) $second->id, 'parents first');
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startRival('locked-delete.php', Speaker::class, (string) $first->id, 'the speaker first');
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $this->assertSame(['deleted', 'deleted'], array_map(fn (InvokedProcess $rival): string => $rival->wait()->output(), $rivals), $rivals[0]->errorOutput().$rivals[1]->errorOutput());
            $this->assertSoftDeleted('speakers', ['id' => $first->id]);
            $this->assertSoftDeleted('speakers', ['id' => $second->id]);
        } finally {
            $this->finishRace($rivals, $panel, $conference);
        }
    }

    protected function orderTheSpeakersOfEveryConference(): void
    {
        config()->set('concurrency.models', [Conference::class]);
    }

    /**
     * @return list<string>
     */
    private function lockedParentTables(): array
    {
        $tables = [];

        foreach (array_column(DB::getQueryLog(), 'query') as $query) {
            if (preg_match('/ from "(panels|conferences)" .*for no key update$/', $query, $match) === 1 && ! in_array($match[1], $tables, true)) {
                $tables[] = $match[1];
            }
        }

        return $tables;
    }

    /**
     * @param  list<InvokedProcess>  $rivals
     */
    private function finishRace(array $rivals, Panel $panel, Conference $conference): void
    {
        $racer = DB::connection('racer');

        if ($racer->transactionLevel() > 0) {
            $racer->rollBack();
        }

        foreach ($rivals as $rival) {
            $rival->wait();
        }

        $racer->transaction(function () use ($racer, $panel, $conference): void {
            $racer->table('conference_speaker')->where('conference_id', $conference->id)->delete();
            $racer->table('speakers')->where('panel_id', $panel->id)->delete();
            $racer->table('conferences')->where('id', $conference->id)->delete();
            $racer->table('panels')->where('id', $panel->id)->delete();
        });
    }

    private static function restore(Model $speaker): mixed
    {
        assert(method_exists($speaker, 'restore'));

        return $speaker->restore();
    }

    /**
     * @return list<int>
     */
    private function revisions(Model ...$parents): array
    {
        return array_map(
            fn (Model $parent): int => (int) DB::table($parent->getTable())->where('id', $parent->getKey())->value('speakers_order_concurrency_revision'),
            array_values($parents),
        );
    }
}
