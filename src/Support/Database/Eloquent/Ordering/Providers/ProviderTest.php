<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Support\Database\Eloquent\Ordering\Provides\OrdersThroughPivots;
use Tests\Fixtures\Support\Agencies\Agency;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingCustomer;
use Tests\Fixtures\Support\Listings\ListingRevision;
use Tests\Fixtures\Support\Playlists\Playlist;
use Tests\Fixtures\Support\Scenes\Scene;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Tours\Tour;
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
    public function it_loads_the_eloquent_concurrency_provider(): void
    {
        $this->assertTrue(Blueprint::hasMacro('concurrencyRevision'));
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
    public function it_refuses_to_boot_with_a_model_whose_ordered_pivots_reach_a_model_without_orders_through_pivots(): void
    {
        $parent = new #[OrdersRelation(ListingRevision::CustomersOrder, relation: 'customers')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Model, $this, ListingCustomer>
             */
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(self::customer(), 'listing_customer', 'listing_id', 'customer_id')->using(ListingCustomer::class)->wherePivotNull('deleted_at');
            }

            /**
             * @return class-string<Model>
             */
            public static function customer(): string
            {
                return (new class extends Model
                {
                    protected $table = 'customers';
                })::class;
            }
        };
        config()->set('concurrency.models', [Listing::class, $parent::class]);

        try {
            $this->app->register(Provider::class, force: true);
        } catch (LogicException $exception) {
            $this->assertSame(
                sprintf('[%s::customers()] orders the pivots of [%2$s], so [%2$s] must use [%3$s].', $parent::class, $parent::customer(), OrdersThroughPivots::class),
                $exception->getMessage(),
            );

            return;
        }

        $this->fail('The provider booted.');
    }

    #[Test]
    public function it_listens_only_to_the_models_that_the_ordered_pivots_of_the_listed_models_reach(): void
    {
        $unordered = new #[OrdersRelation(ListingRevision::ScenesOrder, relation: 'scenes')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return MorphMany<Scene, $this>
             */
            public function scenes(): MorphMany
            {
                return $this->morphMany(Scene::class, 'sceneable');
            }

            /**
             * @return BelongsToMany<Model, $this>
             */
            public function viewers(): BelongsToMany
            {
                return $this->belongsToMany(self::viewer(), 'listing_customer', 'listing_id', 'customer_id');
            }

            /**
             * @return class-string<Model>
             */
            public static function viewer(): string
            {
                return (new class extends Model
                {
                    protected $table = 'customers';
                })::class;
            }
        };
        config()->set('concurrency.models', [Section::class, Playlist::class, $unordered::class, Listing::class, Tour::class, Agency::class]);

        $this->app->register(Provider::class, force: true);

        $this->assertTrue(Event::hasListeners('eloquent.trashed: '.Customer::class));
        $this->assertFalse(Event::hasListeners('eloquent.trashed: '.$unordered::viewer()));
    }
}
