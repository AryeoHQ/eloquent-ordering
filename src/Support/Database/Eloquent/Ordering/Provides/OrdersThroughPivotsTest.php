<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Ordering\Attributes\OrderedThrough;
use Support\Database\Eloquent\Ordering\Events\Reordered;
use Support\Database\Eloquent\Ordering\PositionChange;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Agencies\Agency;
use Tests\Fixtures\Support\Agencies\AgencyRevision;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Customers\TwiceOrderedCustomer;
use Tests\Fixtures\Support\Events\ModelEvents;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingCustomer;
use Tests\Fixtures\Support\Listings\ListingRevision;
use Tests\Fixtures\Support\Listings\NamedListing;
use Tests\Fixtures\Support\Listings\TwiceOrderingListing;
use Tests\Fixtures\Support\Shapes\BelongsToManyShape;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Tours\TourRevision;
use Tests\TestCase;
use Throwable;

#[CoversClass(OrderedThrough::class)]
#[CoversClass(Provider::class)]
#[CoversTrait(OrdersThroughPivots::class)]
class OrdersThroughPivotsTest extends TestCase
{
    /** @var list<array{class-string, mixed, list<int|string>, list<array{int|string, null|int, null|int}>, string}> */
    private array $reorders = [];

    /**
     * @return iterable<string, array{PivotShape}>
     */
    public static function pivots(): iterable
    {
        return PivotShape::pivots();
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_changes_the_order_digest_and_reports_a_leave_when_a_customer_of_a_member_is_soft_deleted(PivotShape $shape): void
    {
        [$parent, [$first, $member, $third]] = $shape->with(3);
        $customer = Customer::query()->findOrFail($member->getAttribute('customer_id'));
        $revision = $shape->digest($parent);
        $position = $shape->find($member->getKey())->getAttribute('position');
        $this->recordReorders();
        $events = ModelEvents::record();

        $customer->delete();

        $this->assertSame([2, null], [$position, $shape->find($member->getKey())->getAttribute('position')]);
        $this->assertNotSame($revision, $shape->digest($parent));
        $this->assertSame([[$parent::class, $parent->getKey(), [$first->getKey(), $third->getKey()], [[$member->getKey(), null, 2]], $shape->digest($parent)]], $this->reorders);
        $this->assertSame([], $events->ofClass($parent::class));
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_restores_the_order_digest_and_reports_an_enter_when_a_customer_of_a_member_is_restored(PivotShape $shape): void
    {
        [$parent, [$first, $member, $third]] = $shape->with(3);
        $customer = Customer::query()->findOrFail($member->getAttribute('customer_id'));
        $revision = $shape->digest($parent);
        $customer->delete();
        $this->recordReorders();
        $events = ModelEvents::record();

        self::restore($customer);

        $this->assertSame(2, $shape->find($member->getKey())->getAttribute('position'));
        $this->assertSame($revision, $shape->digest($parent));
        $this->assertSame([[$parent::class, $parent->getKey(), [$first->getKey(), $member->getKey(), $third->getKey()], [[$member->getKey(), 2, null]], $revision]], $this->reorders);
        $this->assertSame([], $events->ofClass($parent::class));
    }

    #[Test]
    public function it_reports_to_every_listing_tour_and_agency_a_customer_reaches_when_it_is_soft_deleted_or_restored(): void
    {
        $customer = Customer::create();
        [$listing, $tour, $agency] = [Listing::create(), Tour::create(), Agency::create()];
        $listing->customers()->attach($customer);
        $tour->customers()->attach($customer);
        $agency->customers()->attach($customer);
        $pivots = [
            ListingCustomer::query()->where('customer_id', $customer->id)->sole()->getKey(),
            DB::table('customerables')->where('customer_id', $customer->id)->value('id'),
            DB::table('agency_customer')->where('customer_id', $customer->id)->value('id'),
        ];
        $attached = $this->customersRevisions($listing, $tour, $agency);
        $empty = self::emptyDigest();
        $this->recordReorders();

        $customer->delete();

        $this->assertSame([$empty, $empty, $empty], $this->customersRevisions($listing, $tour, $agency));
        $this->assertSame([
            [$listing::class, $listing->id, [], [[$pivots[0], null, 1]], $empty],
            [$tour::class, $tour->id, [], [[$pivots[1], null, 1]], $empty],
            [$agency::class, $agency->id, [], [[$pivots[2], null, 1]], $empty],
        ], $this->reorders);

        $this->reorders = [];
        self::restore($customer);

        $this->assertSame($attached, $this->customersRevisions($listing, $tour, $agency));
        $this->assertSame([
            [$listing::class, $listing->id, [$pivots[0]], [[$pivots[0], 1, null]], $attached[0]],
            [$tour::class, $tour->id, [$pivots[1]], [[$pivots[1], 1, null]], $attached[1]],
            [$agency::class, $agency->id, [$pivots[2]], [[$pivots[2], 1, null]], $attached[2]],
        ], $this->reorders);
    }

    #[Test]
    public function it_reports_once_to_a_listing_that_the_ordered_through_parents_of_a_customer_name_twice(): void
    {
        $listing = TwiceOrderingListing::create();
        $customer = TwiceOrderedCustomer::create();
        $listing->customers()->attach($customer);
        $pivot = ListingCustomer::query()->where('customer_id', $customer->id)->sole()->getKey();
        $this->recordReorders();

        $customer->delete();

        $this->assertSame([[TwiceOrderingListing::class, $listing->id, [], [[$pivot, null, 1]], self::emptyDigest()]], $this->reorders);
    }

    #[Test]
    public function it_commits_the_soft_delete_and_the_restore_of_a_customer_outside_a_transaction(): void
    {
        config()->set('database.connections.committing', config('database.connections.pgsql'));
        $committing = DB::connection('committing');
        $listing = Listing::on('committing')->create();
        $customer = Customer::on('committing')->create();
        $listing->customers()->attach($customer);
        $revision = fn (): string => Listing::on('committing')->findOrFail($listing->id)->concurrencyRevision(ListingRevision::CustomersOrder);
        $attached = $revision();

        try {
            $customer->delete();
            $deleted = $revision();
            self::restore($customer);

            $this->assertSame([self::emptyDigest(), $attached], [$deleted, $revision()]);
        } finally {
            $committing->transaction(function () use ($committing, $listing, $customer): void {
                $committing->table('listing_customer')->where('listing_id', $listing->id)->delete();
                $committing->table('listings')->where('id', $listing->id)->delete();
                $committing->table('customers')->where('id', $customer->id)->delete();
            });
        }
    }

    #[Test]
    public function it_reports_nothing_to_the_parent_of_a_soft_deleted_pivot_of_the_customer(): void
    {
        $customer = Customer::create();
        [$member, $former] = [Listing::create(), Listing::create()];
        $member->customers()->attach($customer);
        $former->customers()->attach($customer);
        $former->customers()->detach($customer);
        $this->recordReorders();

        $customer->delete();

        $this->assertSame([self::emptyDigest(), self::emptyDigest()], [self::customersRevision($member), self::customersRevision($former)]);
        $this->assertSame([$member->id], array_column($this->reorders, 1));
    }

    #[Test]
    public function it_reads_each_position_under_the_parent_lock_before_the_soft_delete_and_writes_no_parent(): void
    {
        $customer = Customer::create();
        $listing = Listing::create();
        $listing->customers()->attach($customer);
        DB::enableQueryLog();

        $customer->delete();

        $queries = array_column(DB::getQueryLog(), 'query');
        $lock = array_find_key($queries, fn (string $query): bool => str_contains($query, 'from "listings"') && str_ends_with($query, 'for no key update'));
        $read = array_find_key($queries, fn (string $query): bool => str_contains($query, 'row_number() over'));
        $write = array_find_key($queries, fn (string $query): bool => str_starts_with($query, 'update "customers" set "deleted_at"'));

        $this->assertIsInt($lock);
        $this->assertIsInt($read);
        $this->assertIsInt($write);
        $this->assertTrue($lock < $read && $read < $write);
        $this->assertSame([], array_values(array_filter($queries, fn (string $query): bool => str_starts_with($query, 'update "listings"'))));
    }

    #[Test]
    public function it_locks_every_parent_the_pivots_of_a_customer_reach_before_it_restores_the_customer(): void
    {
        $customer = Customer::create();
        [$member, $former] = [Listing::create(), Listing::create()];
        $member->customers()->attach($customer);
        $former->customers()->attach($customer);
        $former->customers()->detach($customer);
        $customer->delete();
        DB::enableQueryLog();

        self::restore($customer);

        $queries = DB::getQueryLog();
        $write = array_find_key($queries, fn (array $query): bool => str_starts_with($query['query'], 'update "customers" set "deleted_at"'));
        $locks = array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'from "listings"') && str_ends_with($query['query'], 'for no key update'));

        $this->assertIsInt($write);
        $this->assertSame([$member->id, $former->id], array_values(array_unique(array_map(fn (array $query): mixed => $query['bindings'][0], $locks))));
        $this->assertLessThan($write, max(array_keys($locks)));
    }

    #[Test]
    public function it_locks_the_listing_before_the_customer_when_a_save_changes_a_declared_customer_column(): void
    {
        $listing = Listing::create();
        $customer = Customer::create(['name' => NamedListing::NAME]);
        $listing->customers()->attach($customer);
        DB::enableQueryLog();

        $customer->update(['name' => 'Grace']);

        $queries = array_column(DB::getQueryLog(), 'query');
        $lock = array_find_key($queries, fn (string $query): bool => str_contains($query, 'from "listings"') && str_ends_with($query, 'for no key update'));
        $write = array_find_key($queries, fn (string $query): bool => str_starts_with($query, 'update "customers" set "name"'));

        $this->assertIsInt($lock);
        $this->assertIsInt($write);
        $this->assertTrue($lock < $write);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_reports_nothing_for_a_change_to_a_customer_column_no_relation_declares(PivotShape $shape): void
    {
        [$parent, [$member]] = $shape->with(1);
        $customer = Customer::query()->findOrFail($member->getAttribute('customer_id'));
        $revision = $shape->digest($parent);
        $this->recordReorders();
        $events = ModelEvents::record();

        $customer->update(['visible' => false]);

        $this->assertSame($revision, $shape->digest($parent));
        $this->assertSame([], $this->reorders);
        $this->assertSame([], $events->ofClass($parent::class));
    }

    #[Test]
    public function it_changes_the_order_digest_and_reports_each_leave_and_enter_for_a_declared_customer_column(): void
    {
        $listing = Listing::create();
        [$first, $renamed, $third] = [Customer::create(['name' => NamedListing::NAME]), Customer::create(['name' => NamedListing::NAME]), Customer::create(['name' => NamedListing::NAME])];
        $listing->customers()->attach([$first->id, $renamed->id, $third->id]);
        $pivots = ListingCustomer::query()->where('listing_id', $listing->id)->orderBy('rank_key')->pluck('id')->all();
        $named = fn (): string => NamedListing::query()->findOrFail($listing->id)->concurrencyRevision(ListingRevision::CustomersOrder);
        $before = $named();
        $this->recordReorders();

        $renamed->update(['name' => 'Grace']);
        $left = $named();
        $renamed->update(['name' => 'Linus']);
        $renamed->update(['name' => NamedListing::NAME]);

        $this->assertNotSame($before, $left);
        $this->assertSame($before, $named());
        $this->assertSame([
            [NamedListing::class, $listing->id, [$pivots[0], $pivots[2]], [[$pivots[1], null, 2]], $left],
            [NamedListing::class, $listing->id, $pivots, [[$pivots[1], 2, null]], $before],
        ], $this->reorders);
    }

    #[Test]
    public function it_refuses_a_listener_that_changes_a_declared_customer_column_after_it_prepared_the_order(): void
    {
        [$listing, $customer] = $this->listingOfACustomerNamedAda();

        Customer::updating(function (Customer $customer): void {
            $customer->name = 'Grace';
        });

        $this->assertListenerRefused(
            sprintf('A listener changed [name] of [%s:%s] after its order write was prepared.', Customer::class, $customer->id),
            $listing,
            $customer,
            fn (): bool => $customer->update(['visible' => false]),
        );
    }

    #[Test]
    public function it_refuses_a_listener_that_changes_a_prepared_customer_column_again_and_stops_the_later_listeners(): void
    {
        [$listing, $customer] = $this->listingOfACustomerNamedAda();

        Customer::updating(function (Customer $customer): bool {
            $customer->name = 'Linus';

            return true;
        });

        $this->assertListenerRefused(
            sprintf('A listener changed [name] of [%s:%s] after its order write was prepared.', Customer::class, $customer->id),
            $listing,
            $customer,
            fn (): bool => $customer->update(['name' => 'Grace']),
        );
    }

    #[Test]
    public function it_refuses_a_change_to_a_declared_customer_column_that_no_order_write_prepared(): void
    {
        [$listing, $customer] = $this->listingOfACustomerNamedAda();

        Event::listen('eloquent.updating: *', function (string $event, array $payload): void {
            if (($payload[0] ?? null) instanceof Customer) {
                $payload[0]->name = 'Grace';
            }
        });

        $this->assertListenerRefused(
            sprintf("[%s:%s] changed [name] without a prepared order write. A listener ran after the package's [updating] listener, or stopped it.", Customer::class, $customer->id),
            $listing,
            $customer,
            fn (): bool => $customer->update(['visible' => false]),
        );
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_locks_the_customer_of_an_attach_for_share_after_the_parent_and_before_the_pivot(PivotShape $shape): void
    {
        $parent = $shape->createParent();
        $customer = Customer::create();
        DB::enableQueryLog();

        $shape->customers($parent)->attach($customer);

        $this->assertLockedForShareBetween($shape, sprintf('insert into "%s"', $shape->memberTable()));
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_locks_the_customer_of_a_detach_for_share_after_the_parent_and_before_the_pivot(PivotShape $shape): void
    {
        $parent = $shape->createParent();
        $customer = Customer::create();
        $shape->customers($parent)->attach($customer);
        DB::enableQueryLog();

        $shape->customers($parent)->detach($customer);

        $this->assertLockedForShareBetween($shape, sprintf('update "%s" set "deleted_at"', $shape->memberTable()));
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_locks_the_customer_of_a_pivot_restore_for_share_after_the_parent_and_before_the_pivot(PivotShape $shape): void
    {
        [$parent, [$member]] = $shape->with(1);
        $member->delete();
        $pivot = $shape->find($this->key($member));
        assert(method_exists($pivot, 'restore'));
        DB::enableQueryLog();

        $pivot->restore();

        $this->assertLockedForShareBetween($shape, sprintf('update "%s" set ', $shape->memberTable()));
        $this->assertSame(1, $shape->find($this->key($member))->getAttribute('position'));
    }

    #[Test]
    public function it_holds_an_attach_of_a_customer_until_a_soft_delete_of_the_customer_commits_and_reports_no_enter(): void
    {
        $racer = $this->racer();
        [$listing, $other] = [Listing::on('racer')->create(), Listing::on('racer')->create()];
        [$kept, $customer] = [Customer::on('racer')->create(), Customer::on('racer')->create()];
        $listing->customers()->attach($kept);
        $other->customers()->attach($customer);
        $revision = self::customersRevision($listing);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $customer->delete();

            $rivals[] = $this->startRival('attach.php', (string) $listing->id, (string) $customer->id);
            $waited = $this->awaitLockWaitOrExit($rivals[0]);
            $racer->commit();

            $this->assertSame(
                ['waited' => true, 'attach' => ['attached', []], 'revision' => $revision, 'members' => [self::pivotOf($listing, $kept)]],
                ['waited' => $waited, 'attach' => $this->attachedBy($rivals[0]), 'revision' => self::customersRevision($listing), 'members' => (new BelongsToManyShape)->members($listing)],
            );
        } finally {
            $this->finishRace($rivals, [$listing, $other], [$kept, $customer]);
        }
    }

    #[Test]
    public function it_holds_an_attach_of_a_customer_until_a_restore_of_the_customer_commits_and_reports_the_enter(): void
    {
        $racer = $this->racer();
        [$listing, $other] = [Listing::on('racer')->create(), Listing::on('racer')->create()];
        [$kept, $customer] = [Customer::on('racer')->create(), Customer::on('racer')->create()];
        $listing->customers()->attach($kept);
        $other->customers()->attach($customer);
        $customer->delete();
        $rivals = [];

        try {
            $racer->beginTransaction();
            self::restore($customer);

            $rivals[] = $this->startRival('attach.php', (string) $listing->id, (string) $customer->id);
            $waited = $this->awaitLockWaitOrExit($rivals[0]);
            $racer->commit();
            $attached = $this->attachedBy($rivals[0]);
            $members = [self::pivotOf($listing, $kept), self::pivotOf($listing, $customer)];
            $revision = self::customersRevision($listing);

            $this->assertSame(
                ['waited' => true, 'attach' => ['attached', [[$members, [[$members[1], 2, null]], $revision]]], 'revision' => $revision, 'members' => $members],
                ['waited' => $waited, 'attach' => $attached, 'revision' => self::customersRevision($listing), 'members' => (new BelongsToManyShape)->members($listing)],
            );
        } finally {
            $this->finishRace($rivals, [$listing, $other], [$kept, $customer]);
        }
    }

    #[Test]
    public function it_names_every_parent_that_the_pivots_of_a_customer_reach_without_a_lock(): void
    {
        $customer = Customer::create();
        [$member, $former, $stranger] = [Listing::create(), Listing::create(), Listing::create()];
        $tour = Tour::create();
        $member->customers()->attach($customer);
        $former->customers()->attach($customer);
        $former->customers()->detach($customer);
        $stranger->customers()->attach(Customer::create());
        $tour->customers()->attach($customer);
        $loaded = Customer::query()->findOrFail($customer->id);
        DB::enableQueryLog();

        $parents = $loaded->parentsToLockFirst();

        $this->assertSame(
            [[Listing::class, $member->id], [Listing::class, $former->id], [Tour::class, $tour->id]],
            array_map(fn (Model $parent): array => [$parent::class, $parent->getKey()], $parents),
        );
        $this->assertSame([], array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $query): bool => preg_match('/\bfor (no key )?(update|share)\b/', $query) === 1));
    }

    #[Test]
    public function it_finishes_a_soft_delete_of_a_customer_that_locks_the_parents_first_and_an_attach_of_the_customer_without_a_deadlock(): void
    {
        $racer = $this->racer();
        $listing = Listing::on('racer')->create();
        $customer = Customer::on('racer')->create();
        $listing->customers()->attach($customer);
        $listing->customers()->detach($customer);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('listings')->where('id', $listing->id)->lockForUpdate()->first();

            $rivals[] = $this->startRival('attach.php', (string) $listing->id, (string) $customer->id);
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startRival('locked-delete.php', Customer::class, (string) $customer->id, 'parents first');
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $this->assertSame(['attached', 'deleted'], [$this->attachedBy($rivals[0])[0], $rivals[1]->wait()->output()], $rivals[1]->errorOutput());
            $this->assertSoftDeleted('customers', ['id' => $customer->id]);
            $this->assertSame(self::emptyDigest(), self::customersRevision($listing));
        } finally {
            $this->finishRace($rivals, [$listing], [$customer]);
        }
    }

    #[Test]
    public function it_deadlocks_a_soft_delete_of_a_customer_that_locks_the_customer_before_its_parents_with_an_attach_of_the_customer(): void
    {
        $racer = $this->racer();
        $listing = Listing::on('racer')->create();
        $customer = Customer::on('racer')->create();
        $listing->customers()->attach($customer);
        $listing->customers()->detach($customer);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('listings')->where('id', $listing->id)->lockForUpdate()->first();

            $rivals[] = $this->startRival('attach.php', (string) $listing->id, (string) $customer->id);
            $this->awaitLockWait(1, ...$rivals);

            $rivals[] = $this->startRival('locked-delete.php', Customer::class, (string) $customer->id, 'the customer first');
            $this->awaitLockWait(2, ...$rivals);

            $racer->rollBack();

            $this->assertContains('40P01', [$this->attachedBy($rivals[0])[0], $rivals[1]->wait()->output()], $rivals[1]->errorOutput());
        } finally {
            $this->finishRace($rivals, [$listing], [$customer]);
        }
    }

    #[Test]
    public function it_locks_the_listing_and_reports_the_leave_for_a_customer_soft_delete_in_a_process_that_booted_no_listing(): void
    {
        $racer = $this->racer();
        $listing = Listing::on('racer')->create();
        $customer = Customer::on('racer')->create();
        $listing->customers()->attach($customer);
        $pivot = self::pivotOf($listing, $customer);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $racer->table('listings')->where('id', $listing->id)->lockForUpdate()->first();

            $rivals[] = $this->startRival('locked-delete.php', Customer::class, (string) $customer->id, 'the customer first', 'report');
            $this->awaitLockWait(1, ...$rivals);

            $racer->rollBack();
            $result = $rivals[0]->wait();

            $this->assertSame(['deleted', [[Listing::class, $listing->id, [[$pivot, null, 1]]]]], json_decode($result->output(), true), $result->errorOutput());
            $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        } finally {
            $this->finishRace($rivals, [$listing], [$customer]);
        }
    }

    #[Test]
    public function it_holds_a_pivot_restore_until_a_soft_delete_of_its_customer_commits_and_reports_no_enter(): void
    {
        $racer = $this->racer();
        $listing = Listing::on('racer')->create();
        [$kept, $customer] = [Customer::on('racer')->create(), Customer::on('racer')->create()];
        $listing->customers()->attach([$kept->id, $customer->id]);
        $pivot = self::pivotOf($listing, $customer);
        $listing->customers()->detach($customer);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $customer->delete();

            $rivals[] = $this->startRival('restore-pivot.php', (string) $pivot);
            $waited = $this->awaitLockWaitOrExit($rivals[0]);
            $racer->commit();
            $result = $rivals[0]->wait();

            $this->assertSame(
                ['waited' => true, 'restore' => ['restored', [], null], 'members' => [self::pivotOf($listing, $kept)]],
                ['waited' => $waited, 'restore' => json_decode($result->output(), true), 'members' => (new BelongsToManyShape)->members($listing)],
                $result->errorOutput(),
            );
        } finally {
            $this->finishRace($rivals, [$listing], [$kept, $customer]);
        }
    }

    private static function restore(Model $customer): mixed
    {
        assert(method_exists($customer, 'restore'));

        return $customer->restore();
    }

    private static function pivotOf(Listing $listing, Customer $customer): mixed
    {
        $pivot = ListingCustomer::on($listing->getConnectionName())->withTrashed()->where('listing_id', $listing->id)->where('customer_id', $customer->id)->latest('created_at')->firstOrFail();

        return $pivot->getKey();
    }

    private static function customersRevision(Listing $listing): string
    {
        return (new BelongsToManyShape)->digest($listing);
    }

    private static function emptyDigest(): string
    {
        return substr(hash('sha256', ''), 0, 32);
    }

    /**
     * @return array{Listing, Customer}
     */
    private function listingOfACustomerNamedAda(): array
    {
        $listing = Listing::create();
        $customer = Customer::create(['name' => NamedListing::NAME]);
        $listing->customers()->attach($customer);
        $this->recordReorders();

        return [$listing, $customer];
    }

    /**
     * @param  Closure(): mixed  $write
     */
    private function assertListenerRefused(string $message, Listing $listing, Customer $customer, Closure $write): void
    {
        $revision = self::customersRevision($listing);
        $thrown = null;

        try {
            DB::transaction($write);
        } catch (Throwable $thrown) {
        }

        $this->assertInstanceOf(LogicException::class, $thrown);
        $this->assertSame($message, $thrown->getMessage());
        $this->assertSame(['name' => NamedListing::NAME, 'visible' => true], (array) DB::table('customers')->where('id', $customer->id)->first(['name', 'visible']));
        $this->assertSame($revision, self::customersRevision($listing));
        $this->assertSame([], $this->reorders);
    }

    private function assertLockedForShareBetween(PivotShape $shape, string $write): void
    {
        $queries = array_column(DB::getQueryLog(), 'query');
        $lock = array_find_key($queries, fn (string $query): bool => str_contains($query, sprintf('from "%s"', $shape->parentTable())) && str_ends_with($query, 'for no key update'));
        $share = array_find_key($queries, fn (string $query): bool => str_contains($query, 'from "customers"') && str_ends_with($query, 'for share'));
        $written = array_find_key($queries, fn (string $query): bool => str_starts_with($query, $write));

        $this->assertIsInt($lock);
        $this->assertIsInt($share);
        $this->assertIsInt($written);
        $this->assertTrue($lock < $share && $share < $written);
    }

    private function key(Model $model): int|string
    {
        $key = $model->getKey();

        $this->assertTrue(is_int($key) || is_string($key));

        return $key;
    }

    /**
     * @return array<mixed>
     */
    private function attachedBy(InvokedProcess $rival): array
    {
        $result = $rival->wait();
        $attached = json_decode($result->output(), true);

        $this->assertIsArray($attached, $result->output().$result->errorOutput());

        return $attached;
    }

    /**
     * @param  list<InvokedProcess>  $rivals
     * @param  list<Listing>  $listings
     * @param  list<Customer>  $customers
     */
    private function finishRace(array $rivals, array $listings, array $customers): void
    {
        $racer = DB::connection('racer');

        if ($racer->transactionLevel() > 0) {
            $racer->rollBack();
        }

        foreach ($rivals as $rival) {
            $rival->wait();
        }

        $racer->transaction(function () use ($racer, $listings, $customers): void {
            $listings = array_map(fn (Listing $listing): int => $listing->id, $listings);

            $racer->table('listing_customer')->whereIn('listing_id', $listings)->delete();
            $racer->table('listings')->whereIn('id', $listings)->delete();
            $racer->table('customers')->whereIn('id', array_map(fn (Customer $customer): int => $customer->id, $customers))->delete();
        });
    }

    /**
     * @return list<string>
     */
    private function customersRevisions(Listing $listing, Tour $tour, Agency $agency): array
    {
        return [
            $listing->fresh()?->concurrencyRevision(ListingRevision::CustomersOrder),
            $tour->fresh()?->concurrencyRevision(TourRevision::CustomersOrder),
            $agency->fresh()?->concurrencyRevision(AgencyRevision::CustomersOrder),
        ];
    }

    private function recordReorders(): void
    {
        Event::listen(Reordered::class, function (Reordered $event): void {
            $change = $event->change;
            $this->reorders[] = [
                $change->relation->parent::class,
                $change->relation->parent->getKey(),
                $change->order,
                array_map(fn (PositionChange $changed): array => [$changed->id, $changed->position, $changed->previousPosition], $change->changed),
                $change->revision,
            ];
        });
    }
}
