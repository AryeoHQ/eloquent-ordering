<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Providers\Provider as ConcurrencyProvider;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrderedThrough;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Support\Database\Eloquent\Ordering\Provides\OrdersThroughPivots;
use Support\Database\Eloquent\Ordering\Scopes\SelectsPosition;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Listings\ListingCustomer;
use Tests\Fixtures\Support\Listings\ListingRevision;
use Tests\TestCase;

#[CoversClass(Provider::class)]
class ProviderTest extends TestCase
{
    #[Test]
    public function it_boots_the_provider(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(Provider::class));
    }

    #[Test]
    public function it_registers_a_without_positions_macro_on_every_eloquent_builder(): void
    {
        $this->assertContains(SelectsPosition::class, Customer::query()->withoutPositions()->removedScopes());
        $this->assertContains(SelectsPosition::class, ListingCustomer::query()->withoutPositions()->removedScopes());
        $this->assertSame([], Customer::query()->withoutPositions()->get()->all());
    }

    #[Test]
    public function it_loads_the_eloquent_concurrency_provider(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(ConcurrencyProvider::class));
    }

    #[Test]
    public function it_runs_on_postgres(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    #[Test]
    public function it_runs_on_the_requested_postgres_version(): void
    {
        $requested = getenv('POSTGRES_VERSION');

        if ($requested === false || $requested === '') {
            $this->markTestSkipped('POSTGRES_VERSION is not set.');
        }

        $major = intdiv((int) DB::selectOne('show server_version_num')->server_version_num, 10000);

        $this->assertSame((int) $requested, $major);
    }

    #[Test]
    public function it_refuses_a_parent_whose_ordered_pivots_reach_a_model_without_orders_through_pivots_on_its_first_declaration_read(): void
    {
        $related = (new class extends Model
        {
            use SoftDeletes;

            protected $table = 'customers';
        })::class;
        $parent = new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            /** @var class-string<Model> */
            public static string $related;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Model, $this, ListingCustomer>
             */
            #[OrdersRelation(ListingRevision::CustomersOrder)]
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(self::$related, 'listing_customer', 'listing_id', 'customer_id')->using(ListingCustomer::class)->wherePivotNull('deleted_at');
            }
        };
        $parent::$related = $related;

        $this->assertRefused(
            sprintf('[%s::customers()] orders the pivots of [%2$s], so [%2$s] must use [%3$s].', $parent::class, $related, OrdersThroughPivots::class),
            $parent,
        );
    }

    #[Test]
    public function it_refuses_a_parent_whose_related_model_omits_it_from_ordered_through_on_its_first_declaration_read(): void
    {
        $parent = new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this, ListingCustomer>
             */
            #[OrdersRelation(ListingRevision::CustomersOrder)]
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id', 'customer_id')->using(ListingCustomer::class)->wherePivotNull('deleted_at');
            }
        };

        $this->assertRefused(
            sprintf('[%s::customers()] orders the pivots of [%2$s], so [%2$s] must name [%1$s] in #[%3$s].', $parent::class, Customer::class, OrderedThrough::class),
            $parent,
        );
    }

    #[Test]
    public function it_refuses_a_parent_whose_ordered_pivots_reach_a_model_that_no_order_watches_without_orders_through_pivots(): void
    {
        $related = (new class extends Model
        {
            protected $table = 'customers';
        })::class;
        $parent = new class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            /** @var class-string<Model> */
            public static string $related;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Model, $this, ListingCustomer>
             */
            #[OrdersRelation(ListingRevision::CustomersOrder)]
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(self::$related, 'listing_customer', 'listing_id', 'customer_id')->using(ListingCustomer::class)->wherePivotNull('deleted_at');
            }
        };
        $parent::$related = $related;

        $this->assertRefused(
            sprintf('[%s::customers()] orders the pivots of [%2$s], so [%2$s] must use [%3$s].', $parent::class, $related, OrdersThroughPivots::class),
            $parent,
        );
    }

    #[Test]
    public function it_listens_to_a_model_that_orders_through_pivots_once_the_model_boots(): void
    {
        $viewer = new class extends Model
        {
            protected $table = 'customers';
        };

        $this->assertFalse(Event::hasListeners('eloquent.trashed: '.Customer::class));

        new Customer;

        $this->assertTrue(Event::hasListeners('eloquent.trashed: '.Customer::class));
        $this->assertFalse(Event::hasListeners('eloquent.trashed: '.$viewer::class));
    }

    private function assertRefused(string $message, Model&OrdersRelations $parent): void
    {
        try {
            $parent->orderedRelation(ListingRevision::CustomersOrder);
        } catch (LogicException $exception) {
            $this->assertSame($message, $exception->getMessage());

            return;
        }

        $this->fail('The declaration was read.');
    }
}
