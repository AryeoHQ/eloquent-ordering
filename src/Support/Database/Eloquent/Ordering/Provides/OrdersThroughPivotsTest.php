<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Events\Reordered;
use Support\Database\Eloquent\Ordering\PositionChange;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Tests\Fixtures\Support\Agencies\Agency;
use Tests\Fixtures\Support\Agencies\AgencyRevision;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Events\ModelEvents;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingCustomer;
use Tests\Fixtures\Support\Listings\ListingRevision;
use Tests\Fixtures\Support\Shapes\BelongsToManyShape;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Tours\TourRevision;
use Tests\TestCase;
use Throwable;

#[CoversClass(Provider::class)]
#[CoversClass(OrderedRelations::class)]
#[CoversTrait(OrdersThroughPivots::class)]
#[DefineEnvironment('orderTheCustomersOfEveryParent')]
class OrdersThroughPivotsTest extends TestCase
{
    /** @var list<array{class-string, mixed, list<int|string>, list<array{int|string, null|int, null|int}>, int}> */
    private array $reorders = [];

    /**
     * @return iterable<string, array{PivotShape}>
     */
    public static function pivots(): iterable
    {
        return PivotShape::pivots();
    }

    /**
     * @return iterable<string, array{Closure(Customer): mixed}>
     */
    public static function bypasses(): iterable
    {
        yield 'a query builder soft delete' => [fn (Customer $customer): mixed => Customer::query()->whereKey($customer->id)->delete()];
        yield 'a quiet soft delete' => [fn (Customer $customer): mixed => $customer->deleteQuietly()];
        yield 'a quiet restore' => [function (Customer $customer): mixed {
            $customer->delete();

            return $customer->restoreQuietly();
        }];
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_raises_the_order_revision_and_reports_a_leave_when_a_customer_of_a_member_is_soft_deleted(PivotShape $shape): void
    {
        [$parent, [$first, $member, $third]] = $shape->with(3);
        $customer = Customer::query()->findOrFail($member->getAttribute('customer_id'));
        $revision = $shape->storedRevision($parent);
        $position = $shape->find($member->getKey())->getAttribute('position');
        $this->recordReorders();
        $events = ModelEvents::record();

        $this->runDeferredChecks(fn (): mixed => $customer->delete());

        $this->assertSame(
            ['positions' => [2, null], 'revision' => [4, 5]],
            ['positions' => [$position, $shape->find($member->getKey())->getAttribute('position')], 'revision' => [$revision, $shape->storedRevision($parent)]],
        );
        $this->assertSame([[$parent::class, $parent->getKey(), [$first->getKey(), $third->getKey()], [[$member->getKey(), null, 2]], 5]], $this->reorders);
        $this->assertSame(self::raisedEvents($parent, $shape->column(), 4), self::sqlOf($events->ofClass($parent::class)));
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_raises_the_order_revision_and_reports_an_enter_when_a_customer_of_a_member_is_restored(PivotShape $shape): void
    {
        [$parent, [$first, $member, $third]] = $shape->with(3);
        $customer = Customer::query()->findOrFail($member->getAttribute('customer_id'));
        $customer->delete();
        $this->recordReorders();
        $events = ModelEvents::record();

        $this->runDeferredChecks(fn (): mixed => self::restore($customer));

        $this->assertSame(
            ['position' => 2, 'revision' => 6],
            ['position' => $shape->find($member->getKey())->getAttribute('position'), 'revision' => $shape->storedRevision($parent)],
        );
        $this->assertSame([[$parent::class, $parent->getKey(), [$first->getKey(), $member->getKey(), $third->getKey()], [[$member->getKey(), 2, null]], 6]], $this->reorders);
        $this->assertSame(self::raisedEvents($parent, $shape->column(), 5), self::sqlOf($events->ofClass($parent::class)));
    }

    #[Test]
    public function it_raises_every_listing_tour_and_agency_a_customer_reaches_when_it_is_soft_deleted_or_restored(): void
    {
        $this->recordCustomersOfToursAndAgencies();
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
        $this->recordReorders();

        $this->runDeferredChecks(fn (): mixed => $customer->delete());

        $this->assertSame([3, 3, 3], $this->customersRevisions($listing, $tour, $agency));
        $this->assertSame([
            [$listing::class, $listing->id, [], [[$pivots[0], null, 1]], 3],
            [$tour::class, $tour->id, [], [[$pivots[1], null, 1]], 3],
            [$agency::class, $agency->id, [], [[$pivots[2], null, 1]], 3],
        ], $this->reorders);

        $this->reorders = [];
        $this->runDeferredChecks(fn (): mixed => self::restore($customer));

        $this->assertSame([4, 4, 4], $this->customersRevisions($listing, $tour, $agency));
        $this->assertSame([
            [$listing::class, $listing->id, [$pivots[0]], [[$pivots[0], 1, null]], 4],
            [$tour::class, $tour->id, [$pivots[1]], [[$pivots[1], 1, null]], 4],
            [$agency::class, $agency->id, [$pivots[2]], [[$pivots[2], 1, null]], 4],
        ], $this->reorders);
    }

    #[Test]
    public function it_commits_the_soft_delete_and_the_restore_of_a_customer_outside_a_transaction(): void
    {
        config()->set('database.connections.committing', config('database.connections.pgsql'));
        $committing = DB::connection('committing');
        $listing = Listing::on('committing')->create();
        $customer = Customer::on('committing')->create();
        $listing->customers()->attach($customer);
        $revision = fn (): int => (int) $committing->table('listings')->where('id', $listing->id)->value('customers_order_concurrency_revision');

        try {
            $customer->delete();
            $deleted = $revision();
            self::restore($customer);

            $this->assertSame([3, 4], [$deleted, $revision()]);
        } finally {
            $committing->transaction(function () use ($committing, $listing, $customer): void {
                $committing->table('listing_customer')->where('listing_id', $listing->id)->delete();
                $committing->table('listings')->where('id', $listing->id)->delete();
                $committing->table('customers')->where('id', $customer->id)->delete();
            });
        }
    }

    #[Test]
    public function it_raises_the_parent_of_a_soft_deleted_pivot_of_the_customer_without_a_report(): void
    {
        $customer = Customer::create();
        [$member, $former] = [Listing::create(), Listing::create()];
        $member->customers()->attach($customer);
        $former->customers()->attach($customer);
        $former->customers()->detach($customer);
        $this->recordReorders();

        $this->runDeferredChecks(fn (): mixed => $customer->delete());

        $this->assertSame([3, 4], [$member->refresh()->customers_order_concurrency_revision, $former->refresh()->customers_order_concurrency_revision]);
        $this->assertSame([$member->id], array_column($this->reorders, 1));
    }

    #[Test]
    public function it_reads_each_position_under_the_parent_lock_before_the_soft_delete_and_raises_the_parent_after_it(): void
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
        $raise = array_find_key($queries, fn (string $query): bool => str_starts_with($query, 'update "listings" set "customers_order_concurrency_revision"'));

        $this->assertIsInt($lock);
        $this->assertIsInt($read);
        $this->assertIsInt($write);
        $this->assertIsInt($raise);
        $this->assertTrue($lock < $read && $read < $write && $write < $raise);
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_raises_nothing_for_a_change_to_a_customer_column_no_relation_declares(PivotShape $shape): void
    {
        [$parent, [$member]] = $shape->with(1);
        $customer = Customer::query()->findOrFail($member->getAttribute('customer_id'));
        $this->recordReorders();
        $events = ModelEvents::record();

        $this->runDeferredChecks(fn (): bool => $customer->update(['name' => 'Grace']));

        $this->assertSame(2, $shape->storedRevision($parent));
        $this->assertSame([], $this->reorders);
        $this->assertSame([], $events->ofClass($parent::class));
    }

    /**
     * @param  Closure(Customer): mixed  $bypass
     */
    #[DataProvider('bypasses')]
    #[Test]
    public function it_refuses_the_commit_of_a_customer_soft_delete_or_restore_that_raises_no_listing(Closure $bypass): void
    {
        $customer = Customer::create();
        Listing::create()->customers()->attach($customer);

        $this->assertRefused(fn (): mixed => $bypass($customer));
    }

    #[Test]
    public function it_refuses_the_commit_of_a_customer_soft_delete_that_raises_no_tour_or_agency(): void
    {
        $this->recordCustomersOfToursAndAgencies();
        [$touring, $represented] = [Customer::create(), Customer::create()];
        Tour::create()->customers()->attach($touring);
        Agency::create()->customers()->attach($represented);

        $this->assertRefused(fn (): mixed => $touring->deleteQuietly());
        $this->assertRefused(fn (): mixed => $represented->deleteQuietly());
    }

    #[DefineEnvironment('orderTheCustomersOfAListingByName')]
    #[Test]
    public function it_raises_the_order_revision_and_reports_each_leave_and_enter_for_a_declared_customer_column(): void
    {
        self::recordCustomerNames();
        $listing = Listing::create();
        [$first, $renamed, $third] = [Customer::create(['name' => 'Ada']), Customer::create(['name' => 'Ada']), Customer::create(['name' => 'Ada'])];
        $listing->customers()->attach([$first->id, $renamed->id, $third->id]);
        $pivots = ListingCustomer::query()->where('listing_id', $listing->id)->orderBy('rank_key')->pluck('id')->all();
        $this->recordReorders();

        $this->runDeferredChecks(fn (): bool => $renamed->update(['name' => 'Grace']));
        $this->runDeferredChecks(fn (): bool => $renamed->update(['name' => 'Linus']));
        $this->runDeferredChecks(fn (): bool => $renamed->update(['name' => 'Ada']));

        $this->assertSame(7, $listing->refresh()->customers_order_concurrency_revision);
        $this->assertSame([
            [self::listingsByCustomerName(), $listing->id, [$pivots[0], $pivots[2]], [[$pivots[1], null, 2]], 5],
            [self::listingsByCustomerName(), $listing->id, $pivots, [[$pivots[1], 2, null]], 7],
        ], $this->reorders);
        $this->assertRefused(fn (): int => Customer::query()->whereKey($renamed->id)->update(['name' => 'Grace']));
    }

    #[DefineEnvironment('orderTheCustomersOfAListingByName')]
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

    #[DefineEnvironment('orderTheCustomersOfAListingByName')]
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

        $queries = array_column(DB::getQueryLog(), 'query');
        $lock = array_find_key($queries, fn (string $query): bool => str_contains($query, sprintf('from "%s"', $shape->parentTable())) && str_ends_with($query, 'for no key update'));
        $share = array_find_key($queries, fn (string $query): bool => str_contains($query, 'from "customers"') && str_ends_with($query, 'for share'));
        $insert = array_find_key($queries, fn (string $query): bool => str_starts_with($query, sprintf('insert into "%s"', $shape->memberTable())));

        $this->assertIsInt($lock);
        $this->assertIsInt($share);
        $this->assertIsInt($insert);
        $this->assertTrue($lock < $share && $share < $insert);
    }

    #[Test]
    public function it_holds_an_attach_of_a_customer_until_a_soft_delete_of_the_customer_commits_and_reports_no_enter(): void
    {
        $racer = $this->racer();
        [$listing, $other] = [Listing::on('racer')->create(), Listing::on('racer')->create()];
        [$kept, $customer] = [Customer::on('racer')->create(), Customer::on('racer')->create()];
        $listing->customers()->attach($kept);
        $other->customers()->attach($customer);
        $rivals = [];

        try {
            $racer->beginTransaction();
            $customer->delete();

            $rivals[] = $this->startRival('attach.php', (string) $listing->id, (string) $customer->id);
            $waited = $this->awaitLockWaitOrExit($rivals[0]);
            $racer->commit();

            $this->assertSame(
                ['waited' => true, 'attach' => ['attached', []], 'revision' => 3, 'members' => [self::pivotOf($listing, $kept)]],
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

            $this->assertSame(
                ['waited' => true, 'attach' => ['attached', [[$members, [[$members[1], 2, null]], 3]]], 'revision' => 3, 'members' => $members],
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
            $this->assertSame(5, self::customersRevision($listing));
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

    protected function orderTheCustomersOfEveryParent(): void
    {
        config()->set('concurrency.models', [Listing::class, Tour::class, Agency::class]);
    }

    protected function orderTheCustomersOfAListingByName(): void
    {
        config()->set('concurrency.models', [self::listingsByCustomerName()]);
    }

    private static function restore(Model $customer): mixed
    {
        assert(method_exists($customer, 'restore'));

        return $customer->restore();
    }

    /**
     * @return class-string<Model&OrdersRelations&Revisioned>
     */
    private static function listingsByCustomerName(): string
    {
        return (new #[OrdersRelation(ListingRevision::CustomersOrder, relation: 'customers', membership: ['customers.name'])] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this, ListingCustomer>
             */
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id')
                    ->using(ListingCustomer::class)
                    ->wherePivotNull('deleted_at')
                    ->where('customers.name', 'Ada');
            }
        })::class;
    }

    private static function recordCustomerNames(): void
    {
        OrderedRelations::dropTrigger('customers', ListingRevision::CustomersOrder->value);
        OrderedRelations::createRelatedTrigger('customers', 'listings', 'listing_customer', 'customer_id', 'listing_id', ListingRevision::CustomersOrder->value, membership: ['name']);
    }

    private static function pivotOf(Listing $listing, Customer $customer): mixed
    {
        return (new BelongsToManyShape)->pivotOf($listing, $customer)->getKey();
    }

    private static function customersRevision(Listing $listing): int
    {
        return (new BelongsToManyShape)->storedRevision($listing);
    }

    /**
     * @return array{Listing, Customer}
     */
    private function listingOfACustomerNamedAda(): array
    {
        self::recordCustomerNames();
        $listing = Listing::create();
        $customer = Customer::create(['name' => 'Ada']);
        $listing->customers()->attach($customer);
        $this->recordReorders();

        return [$listing, $customer];
    }

    /**
     * @param  Closure(): mixed  $write
     */
    private function assertListenerRefused(string $message, Listing $listing, Customer $customer, Closure $write): void
    {
        $thrown = null;

        try {
            $this->runDeferredChecks($write);
        } catch (Throwable $thrown) {
        }

        $this->assertInstanceOf(LogicException::class, $thrown);
        $this->assertSame($message, $thrown->getMessage());
        $this->assertSame(['name' => 'Ada', 'visible' => true], (array) DB::table('customers')->where('id', $customer->id)->first(['name', 'visible']));
        $this->assertSame(2, self::customersRevision($listing));
        $this->assertSame([], $this->reorders);
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

    private function recordCustomersOfToursAndAgencies(): void
    {
        OrderedRelations::createRelatedMorphTrigger('customers', 'tours', 'customerables', 'customer_id', 'customerable', (new Tour)->getMorphClass(), TourRevision::CustomersOrder->value);
        OrderedRelations::createRelatedTrigger('customers', 'agencies', 'agency_customer', 'customer_id', 'agency_id', AgencyRevision::CustomersOrder->value);
    }

    /**
     * @return list<int>
     */
    private function customersRevisions(Listing $listing, Tour $tour, Agency $agency): array
    {
        return array_map(
            fn (Model $parent): int => (int) DB::table($parent->getTable())->where('id', $parent->getKey())->value('customers_order_concurrency_revision'),
            [$listing, $tour, $agency],
        );
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
