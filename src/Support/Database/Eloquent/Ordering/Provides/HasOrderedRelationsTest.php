<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Events\Reordered;
use Support\Database\Eloquent\Ordering\Placement;
use Support\Database\Eloquent\Ordering\PositionChange;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Clips\Clip;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Customers\Customerable;
use Tests\Fixtures\Support\Events\ModelEvents;
use Tests\Fixtures\Support\Listings\ListingCustomer;
use Tests\Fixtures\Support\Listings\ListingRevision;
use Tests\Fixtures\Support\Playlists\Playlist;
use Tests\Fixtures\Support\Playlists\PlaylistRevision;
use Tests\Fixtures\Support\Scenes\Scene;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Tours\TourRevision;
use Tests\Fixtures\Support\Tracks\Track;
use Tests\Fixtures\Support\Videos\Video;
use Tests\Fixtures\Support\Videos\VideoRevision;
use Tests\TestCase;

#[CoversClass(OrdersRelation::class)]
#[CoversTrait(HasOrderedRelations::class)]
class HasOrderedRelationsTest extends TestCase
{
    /** @var list<array{mixed, list<int|string>, list<array{int|string, null|int, null|int}>, int}> */
    private array $reorders = [];

    /**
     * @return iterable<string, array{Closure(): (Model&OrdersRelations), NamesConcurrencyRevision, string}>
     */
    public static function softDeleteColumns(): iterable
    {
        yield 'has many' => [static function (): Model&OrdersRelations {
            $item = new class extends Model implements Ordered
            {
                use IsOrdered;
                use SoftDeletes;

                public const string DELETED_AT = 'removed_at';

                protected $table = 'assets';
            };

            $parent = new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] class extends Model implements OrdersRelations, Revisioned
            {
                use HasConcurrencyRevisions;
                use HasOrderedRelations;

                /** @var class-string<Model&Ordered> */
                public string $itemClass = Asset::class;

                protected $table = 'sections';

                /**
                 * @return HasMany<Model&Ordered, $this>
                 */
                public function assets(): HasMany
                {
                    return $this->hasMany($this->itemClass, 'section_id');
                }
            };
            $parent->itemClass = $item::class;

            return $parent;
        }, SectionRevision::AssetsOrder, 'assets()'];

        yield 'morph many' => [static function (): Model&OrdersRelations {
            $item = new class extends Model implements Ordered
            {
                use IsOrdered;
                use SoftDeletes;

                public const string DELETED_AT = 'removed_at';

                protected $table = 'scenes';
            };

            $parent = new #[OrdersRelation(TourRevision::ScenesOrder, relation: 'scenes')] class extends Model implements OrdersRelations, Revisioned
            {
                use HasConcurrencyRevisions;
                use HasOrderedRelations;

                /** @var class-string<Model&Ordered> */
                public string $itemClass = Scene::class;

                protected $table = 'tours';

                /**
                 * @return MorphMany<Model&Ordered, $this>
                 */
                public function scenes(): MorphMany
                {
                    return $this->morphMany($this->itemClass, 'sceneable');
                }
            };
            $parent->itemClass = $item::class;

            return $parent;
        }, TourRevision::ScenesOrder, 'scenes()'];

        yield 'belongs to many' => [static function (): Model&OrdersRelations {
            $pivot = new class extends Pivot implements Ordered
            {
                use IsOrdered;
                use SoftDeletes;

                public const string DELETED_AT = 'removed_at';

                protected $table = 'listing_customer';
            };

            $parent = new #[OrdersRelation(ListingRevision::CustomersOrder, relation: 'customers')] class extends Model implements OrdersRelations, Revisioned
            {
                use HasConcurrencyRevisions;
                use HasOrderedRelations;

                /** @var class-string<Pivot&Ordered> */
                public string $pivotClass = ListingCustomer::class;

                protected $table = 'listings';

                /**
                 * @return BelongsToMany<Customer, $this, Pivot&Ordered>
                 */
                public function customers(): BelongsToMany
                {
                    return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id')->using($this->pivotClass);
                }
            };
            $parent->pivotClass = $pivot::class;

            return $parent;
        }, ListingRevision::CustomersOrder, 'customers()'];

        yield 'morph to many' => [static function (): Model&OrdersRelations {
            $pivot = new class extends MorphPivot implements Ordered
            {
                use IsOrdered;
                use SoftDeletes;

                public const string DELETED_AT = 'removed_at';

                protected $table = 'customerables';
            };

            $parent = new #[OrdersRelation(TourRevision::CustomersOrder, relation: 'customers')] class extends Model implements OrdersRelations, Revisioned
            {
                use HasConcurrencyRevisions;
                use HasOrderedRelations;

                /** @var class-string<MorphPivot&Ordered> */
                public string $pivotClass = Customerable::class;

                protected $table = 'tours';

                /**
                 * @return MorphToMany<Customer, $this, MorphPivot&Ordered>
                 */
                public function customers(): MorphToMany
                {
                    return $this->morphToMany(Customer::class, 'customerable')->using($this->pivotClass);
                }
            };
            $parent->pivotClass = $pivot::class;

            return $parent;
        }, TourRevision::CustomersOrder, 'customers()'];
    }

    /**
     * @return iterable<string, array{Closure(string): (Model&OrdersRelations), NamesConcurrencyRevision, string}>
     */
    public static function ownerKeys(): iterable
    {
        yield 'has many' => [static function (string $ownerKey): Model&OrdersRelations {
            $parent = new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] class extends Model implements OrdersRelations, Revisioned
            {
                use HasConcurrencyRevisions;
                use HasOrderedRelations;

                public string $ownerKey = 'id';

                protected $table = 'sections';

                /**
                 * @return HasMany<Asset, $this>
                 */
                public function assets(): HasMany
                {
                    return $this->hasMany(Asset::class, 'section_id', $this->ownerKey);
                }
            };
            $parent->ownerKey = $ownerKey;

            return $parent;
        }, SectionRevision::AssetsOrder, 'assets()'];

        yield 'morph many' => [static function (string $ownerKey): Model&OrdersRelations {
            $parent = new #[OrdersRelation(TourRevision::ScenesOrder, relation: 'scenes')] class extends Model implements OrdersRelations, Revisioned
            {
                use HasConcurrencyRevisions;
                use HasOrderedRelations;

                public string $ownerKey = 'id';

                protected $table = 'tours';

                /**
                 * @return MorphMany<Scene, $this>
                 */
                public function scenes(): MorphMany
                {
                    return $this->morphMany(Scene::class, 'sceneable', localKey: $this->ownerKey);
                }
            };
            $parent->ownerKey = $ownerKey;

            return $parent;
        }, TourRevision::ScenesOrder, 'scenes()'];

        yield 'belongs to many' => [static function (string $ownerKey): Model&OrdersRelations {
            $parent = new #[OrdersRelation(ListingRevision::CustomersOrder, relation: 'customers')] class extends Model implements OrdersRelations, Revisioned
            {
                use HasConcurrencyRevisions;
                use HasOrderedRelations;

                public string $ownerKey = 'id';

                protected $table = 'listings';

                /**
                 * @return BelongsToMany<Customer, $this, ListingCustomer>
                 */
                public function customers(): BelongsToMany
                {
                    return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id', 'customer_id', $this->ownerKey)->using(ListingCustomer::class);
                }
            };
            $parent->ownerKey = $ownerKey;

            return $parent;
        }, ListingRevision::CustomersOrder, 'customers()'];

        yield 'morph to many' => [static function (string $ownerKey): Model&OrdersRelations {
            $parent = new #[OrdersRelation(TourRevision::CustomersOrder, relation: 'customers')] class extends Model implements OrdersRelations, Revisioned
            {
                use HasConcurrencyRevisions;
                use HasOrderedRelations;

                public string $ownerKey = 'id';

                protected $table = 'tours';

                /**
                 * @return MorphToMany<Customer, $this, Customerable>
                 */
                public function customers(): MorphToMany
                {
                    return $this->morphToMany(Customer::class, 'customerable', parentKey: $this->ownerKey)->using(Customerable::class);
                }
            };
            $parent->ownerKey = $ownerKey;

            return $parent;
        }, TourRevision::CustomersOrder, 'customers()'];
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function eloquentWrites(): iterable
    {
        yield from Shape::crossed([
            'a create' => [static function (Shape $shape): array {
                $parent = $shape->createParent();

                return [[$parent], fn (): Model => $shape->add($parent), ['saving', 'saved']];
            }],
            'a soft delete' => [static function (Shape $shape): array {
                [$parent, [, $member]] = $shape->with(2);

                return [[$parent], fn (): mixed => $member->delete(), ['saving', 'updating', 'updated', 'saved']];
            }],
            'a force delete' => [static function (Shape $shape): array {
                [$parent, [, $member]] = $shape->with(2);
                assert(method_exists($member, 'forceDelete'));

                return [[$parent], fn (): mixed => $member->forceDelete(), []];
            }],
            'a restore' => [static function (Shape $shape): array {
                [$parent, [, $member]] = $shape->with(2);
                $member->delete();
                assert(method_exists($member, 'restore'));

                return [[$parent], fn (): mixed => $member->restore(), ['saving', 'updating', 'updated', 'saved']];
            }],
            'a save that changes the parent' => [static function (Shape $shape): array {
                [$source, [, $moved]] = $shape->with(2);
                [$destination] = $shape->with(1);
                $shape->associate($moved, $destination);

                return [[$source, $destination], fn (): bool => $moved->save(), ['saving', 'updating', 'updated', 'saved']];
            }],
        ]);

        foreach (PivotShape::pivots() as $name => [$shape]) {
            yield "{$name}, an attach" => [$shape, static function (Shape $shape): array {
                assert($shape instanceof PivotShape);
                $parent = $shape->createParent();
                $shape->customers($parent)->attach(Customer::create());

                return [[$parent], function () use ($shape, $parent): void {
                    $shape->customers($parent)->attach(Customer::create());
                }, ['saving', 'saved']];
            }];

            yield "{$name}, a detach" => [$shape, static function (Shape $shape): array {
                assert($shape instanceof PivotShape);
                $parent = $shape->createParent();
                [$first, $second] = [Customer::create(), Customer::create()];
                $shape->customers($parent)->attach([$first->id, $second->id]);

                return [[$parent], fn (): int => $shape->customers($parent)->detach($second), ['saving', 'updating', 'updated', 'saved']];
            }];
        }
    }

    /**
     * @param  Closure(Shape): array{list<Model>, Closure(): mixed, list<string>}  $prepare
     */
    #[DataProvider('eloquentWrites')]
    #[Test]
    public function it_fires_the_model_events_of_each_parent_whose_order_an_eloquent_write_changes(Shape $shape, Closure $prepare): void
    {
        [$parents, $write, $member] = $prepare($shape);
        $revisions = array_map(fn (Model $parent): int => $shape->storedRevision($parent), $parents);
        $events = ModelEvents::record();

        $write();

        $this->assertSame(
            array_merge(...array_map(fn (Model $parent, int $revision): array => self::raisedEvents($parent, $shape->column(), $revision), $parents, $revisions)),
            self::sqlOf($events->ofClass($parents[0]::class)),
        );
        $this->assertSame($member, array_column($events->ofClass($shape->memberClass()), 0));
    }

    #[Test]
    public function it_fires_the_model_events_of_a_parent_that_is_itself_an_ordered_item(): void
    {
        $video = $this->addVideo(Section::create());
        [$first, $moved] = [$this->addClip($video), $this->addClip($video)];
        $this->travel(1)->minutes();
        $row = ModelEvents::row($moved);
        $column = $video->concurrencyRevisionColumn(VideoRevision::ClipsOrder);
        $revision = $video->concurrencyRevision(VideoRevision::ClipsOrder);
        $events = ModelEvents::record();
        DB::enableQueryLog();

        $moved->move(Placement::first(), expected: null);

        $this->assertSame([
            ...self::savedEvents($moved, $row, ModelEvents::row($moved)),
            ...self::raisedEvents($video, $column, $revision),
        ], self::sqlOf($events->fired));
        $this->assertSame([1, $revision + 1], [(int) DB::table('videos')->where('id', $video->id)->value('concurrency_revision'), (int) DB::table('videos')->where('id', $video->id)->value($column)]);
        $this->assertSame([], array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $query): bool => str_contains($query, 'from "sections"'))));
        $this->assertSame([$moved->id, $first->id], $video->orderedRelation(VideoRevision::ClipsOrder)->query()->pluck('id')->all());
    }

    #[Test]
    public function it_returns_the_ordered_relation_of_a_revision(): void
    {
        $section = Section::create();

        $relation = $section->orderedRelation(SectionRevision::AssetsOrder);

        $this->assertSame($section, $relation->parent);
        $this->assertSame(SectionRevision::AssetsOrder, $relation->revision);
    }

    #[Test]
    public function it_counts_the_order_revision_among_the_concurrency_revision_columns_without_a_query(): void
    {
        DB::enableQueryLog();

        $columns = (new Section)->concurrencyRevisionColumns();

        $this->assertSame(['self' => 'concurrency_revision', 'assets_order' => 'assets_order_concurrency_revision', 'videos_order' => 'videos_order_concurrency_revision'], $columns);
        $this->assertSame([], DB::getQueryLog());
    }

    #[Test]
    public function it_rejects_a_revision_that_orders_no_relation(): void
    {
        $this->expectException(LogicException::class);

        Section::create()->orderedRelation(SectionRevision::FeaturedAssetsOrder);
    }

    #[Test]
    public function it_lets_one_model_use_both_traits(): void
    {
        $video = new Video;

        $this->assertContains(HasOrderedRelations::class, class_uses($video));
        $this->assertContains(IsOrdered::class, class_uses($video));
        $this->assertInstanceOf(OrdersRelations::class, $video);
        $this->assertInstanceOf(Ordered::class, $video);
    }

    #[Test]
    public function it_resolves_both_sides_of_a_model_that_is_item_and_parent(): void
    {
        $section = Section::create();
        $video = $this->addVideo($section);

        $item = $video->orderingRelation();
        $parent = $video->orderedRelation(VideoRevision::ClipsOrder);

        $this->assertSame($section, $item->parent);
        $this->assertSame(SectionRevision::VideosOrder, $item->revision);
        $this->assertSame($video, $parent->parent);
        $this->assertSame(VideoRevision::ClipsOrder, $parent->revision);
    }

    #[Test]
    public function it_increments_the_order_revision_of_the_parent_each_write_changes(): void
    {
        $section = Section::create();

        $video = $this->addVideo($section);

        $this->assertSame(2, $section->concurrencyRevision(SectionRevision::VideosOrder));
        $this->assertSame(2, (int) DB::table('sections')->where('id', $section->id)->value('videos_order_concurrency_revision'));

        $clip = new Clip;
        $clip->video()->associate($video);
        $clip->save();

        $this->assertSame(2, $video->concurrencyRevision(VideoRevision::ClipsOrder));
        $this->assertSame(2, (int) DB::table('videos')->where('id', $video->id)->value('clips_order_concurrency_revision'));
        $this->assertSame(1, (int) DB::table('videos')->where('id', $video->id)->value('concurrency_revision'));
        $this->assertSame(2, (int) DB::table('sections')->where('id', $section->id)->value('videos_order_concurrency_revision'));
        $this->assertSame([$clip->id], $video->orderedRelation(VideoRevision::ClipsOrder)->query()->pluck('id')->all());
    }

    #[Test]
    public function it_gives_each_role_only_its_own_relation_method(): void
    {
        $this->assertTrue((new ReflectionClass(Section::class))->hasMethod('orderedRelation'));
        $this->assertFalse((new ReflectionClass(Section::class))->hasMethod('orderingRelation'));
        $this->assertTrue((new ReflectionClass(Asset::class))->hasMethod('orderingRelation'));
        $this->assertFalse((new ReflectionClass(Asset::class))->hasMethod('orderedRelation'));
    }

    #[Test]
    public function it_rejects_a_parent_that_orders_one_item_class_in_two_relations(): void
    {
        $parent = new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] #[OrdersRelation(SectionRevision::FeaturedAssetsOrder, relation: 'featuredAssets')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id');
            }

            /**
             * @return HasMany<Asset, $this>
             */
            public function featuredAssets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id');
            }
        };

        try {
            $parent->orderedRelation(SectionRevision::AssetsOrder);
            $this->fail('A parent that orders one item class in two relations was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('in more than one relation', $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_a_relation_that_is_not_a_has_many_relation_to_an_ordered_model(): void
    {
        $parent = new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'sections')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Section, $this>
             */
            public function sections(): HasMany
            {
                return $this->hasMany(Section::class, 'id');
            }
        };

        $this->expectException(LogicException::class);

        $parent->orderedRelation(SectionRevision::AssetsOrder);
    }

    #[Test]
    public function it_calls_no_method_that_does_not_declare_a_has_many_relation(): void
    {
        $parent = new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'delete')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';
        };

        $parent->save();

        try {
            $parent->orderedRelation(SectionRevision::AssetsOrder);
            $this->fail('A method that declares no HasMany relation was accepted.');
        } catch (LogicException) {
            $this->assertTrue(DB::table('sections')->where('id', $parent->getKey())->exists());
        }
    }

    #[Test]
    public function it_rejects_a_belongs_to_many_relation_without_an_ordered_pivot(): void
    {
        $parent = new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'customers')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this>
             */
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id');
            }
        };

        try {
            $parent->orderedRelation(SectionRevision::AssetsOrder);
            $this->fail('A BelongsToMany relation without an Ordered pivot was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('must name an Ordered pivot class through using()', $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_the_inverse_side_of_a_morph_to_many_relation(): void
    {
        $parent = new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'tours')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'customers';

            /**
             * @return MorphToMany<Tour, $this, Customerable>
             */
            public function tours(): MorphToMany
            {
                return $this->morphedByMany(Tour::class, 'customerable', 'customerables', 'customer_id')->using(Customerable::class);
            }
        };

        try {
            $parent->orderedRelation(SectionRevision::AssetsOrder);
            $this->fail('The inverse side of a MorphToMany relation was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('inverse side of a MorphToMany relation', $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_a_has_many_relation_with_an_owner_key_other_than_id(): void
    {
        $parent = new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_legacy_id', 'legacy_id');
            }
        };

        try {
            $parent->orderedRelation(SectionRevision::AssetsOrder);
            $this->fail('A HasMany relation with a custom owner key was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString($parent::class, $exception->getMessage());
            $this->assertStringContainsString('assets()', $exception->getMessage());
            $this->assertStringContainsString('legacy_id', $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_a_parent_whose_primary_key_is_not_named_id(): void
    {
        $parent = new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            protected $primaryKey = 'legacy_id';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_id');
            }
        };

        try {
            $parent->orderedRelation(SectionRevision::AssetsOrder);
            $this->fail('A parent whose primary key is not named id was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString($parent::class, $exception->getMessage());
            $this->assertStringContainsString('assets()', $exception->getMessage());
            $this->assertStringContainsString('legacy_id', $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_an_item_whose_primary_key_is_not_named_id(): void
    {
        $item = new class extends Model implements Ordered, OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;
            use IsOrdered;

            protected $table = 'assets';

            protected $primaryKey = 'legacy_id';
        };

        $parent = new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            /** @var class-string<Model&Ordered> */
            public string $itemClass = Asset::class;

            protected $table = 'sections';

            /**
             * @return HasMany<Model&Ordered, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany($this->itemClass, 'section_id');
            }
        };
        $parent->itemClass = $item::class;

        try {
            $parent->orderedRelation(SectionRevision::AssetsOrder);
            $this->fail('An item whose primary key is not named id was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString($parent::class, $exception->getMessage());
            $this->assertStringContainsString('assets()', $exception->getMessage());
            $this->assertStringContainsString('legacy_id', $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_a_belongs_to_many_relation_with_a_parent_key_other_than_id(): void
    {
        $parent = new #[OrdersRelation(ListingRevision::CustomersOrder, relation: 'customers')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this, ListingCustomer>
             */
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id', 'customer_id', 'legacy_id')->using(ListingCustomer::class);
            }
        };

        try {
            $parent->orderedRelation(ListingRevision::CustomersOrder);
            $this->fail('A BelongsToMany relation with a custom parent key was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString($parent::class, $exception->getMessage());
            $this->assertStringContainsString('customers()', $exception->getMessage());
            $this->assertStringContainsString('legacy_id', $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_a_pivot_whose_primary_key_is_not_named_id(): void
    {
        $pivot = new class extends Pivot implements Ordered, OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;
            use IsOrdered;

            protected $table = 'listing_customer';

            protected $primaryKey = 'legacy_id';
        };

        $parent = new #[OrdersRelation(ListingRevision::CustomersOrder, relation: 'customers')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            /** @var class-string<Pivot&Ordered> */
            public string $pivotClass = ListingCustomer::class;

            protected $table = 'listings';

            /**
             * @return BelongsToMany<Customer, $this, Pivot&Ordered>
             */
            public function customers(): BelongsToMany
            {
                return $this->belongsToMany(Customer::class, 'listing_customer', 'listing_id')->using($this->pivotClass);
            }
        };
        $parent->pivotClass = $pivot::class;

        try {
            $parent->orderedRelation(ListingRevision::CustomersOrder);
            $this->fail('A pivot whose primary key is not named id was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString($parent::class, $exception->getMessage());
            $this->assertStringContainsString('customers()', $exception->getMessage());
            $this->assertStringContainsString('legacy_id', $exception->getMessage());
        }
    }

    /**
     * @param  Closure(): (Model&OrdersRelations)  $parent
     */
    #[DataProvider('softDeleteColumns')]
    #[Test]
    public function it_rejects_an_item_that_soft_deletes_through_a_column_other_than_deleted_at(Closure $parent, NamesConcurrencyRevision $revision, string $relation): void
    {
        $parent = $parent();

        foreach (['first', 'second'] as $call) {
            try {
                $parent->orderedRelation($revision);
                $this->fail(sprintf('The %s call accepted an item that soft deletes through [removed_at].', $call));
            } catch (LogicException $exception) {
                $this->assertStringContainsString($parent::class, $exception->getMessage());
                $this->assertStringContainsString($relation, $exception->getMessage());
                $this->assertStringContainsString('removed_at', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function it_rejects_a_misdeclared_relation_on_every_call(): void
    {
        $parent = new #[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')] class extends Model implements OrdersRelations, Revisioned
        {
            use HasConcurrencyRevisions;
            use HasOrderedRelations;

            protected $table = 'sections';

            /**
             * @return HasMany<Asset, $this>
             */
            public function assets(): HasMany
            {
                return $this->hasMany(Asset::class, 'section_legacy_id', 'legacy_id');
            }
        };

        foreach (['first', 'second', 'third'] as $call) {
            try {
                $parent->orderedRelation(SectionRevision::AssetsOrder);
                $this->fail(sprintf('The %s call accepted a HasMany relation with a custom owner key.', $call));
            } catch (LogicException $exception) {
                $this->assertStringContainsString('legacy_id', $exception->getMessage());
            }
        }
    }

    /**
     * @param  Closure(string): (Model&OrdersRelations)  $parent
     */
    #[DataProvider('ownerKeys')]
    #[Test]
    public function it_validates_a_relation_again_when_another_instance_returns_other_keys(Closure $parent, NamesConcurrencyRevision $revision, string $relation): void
    {
        $this->assertSame($revision, $parent('id')->orderedRelation($revision)->revision);

        try {
            $parent('legacy_id')->orderedRelation($revision);
            $this->fail('A second instance that returns the owner key [legacy_id] was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString($relation, $exception->getMessage());
            $this->assertStringContainsString('legacy_id', $exception->getMessage());
        }
    }

    #[Test]
    public function it_raises_the_order_revision_and_reports_each_leave_and_enter_for_a_membership_change_of_the_parent(): void
    {
        [$playlist, [$firstAudio, $firstVideo, $secondAudio, $secondVideo, $thirdAudio]] = $this->playlist('audio', ['audio', 'video', 'audio', 'video', 'audio']);
        $keys = $this->trackKeys($playlist);
        $revision = $this->tracksRevision($playlist);
        $this->recordReorders();

        $playlist->kind = 'video';
        $this->runDeferredChecks(fn (): bool => $playlist->save());

        $this->assertSame($revision + 1, $this->tracksRevision($playlist));
        $this->assertSame($revision + 1, $playlist->concurrencyRevision(PlaylistRevision::TracksOrder));
        $this->assertSame($keys, $this->trackKeys($playlist));
        $this->assertSame([[
            $playlist->id,
            [$firstVideo->id, $secondVideo->id],
            [[$firstAudio->id, null, 1], [$secondAudio->id, null, 2], [$thirdAudio->id, null, 3], [$firstVideo->id, 1, null], [$secondVideo->id, 2, null]],
            $revision + 1,
        ]], $this->reorders);
    }

    #[Test]
    public function it_raises_the_order_revision_without_a_report_for_a_membership_change_of_the_parent_that_keeps_every_member(): void
    {
        [$playlist] = $this->playlist('audio', ['podcast']);
        $revision = $this->tracksRevision($playlist);
        $this->recordReorders();

        $playlist->kind = 'video';
        $this->runDeferredChecks(fn (): bool => $playlist->save());

        $this->assertSame($revision + 1, $this->tracksRevision($playlist));
        $this->assertSame([], $this->reorders);
    }

    #[Test]
    public function it_raises_the_order_revision_in_the_update_that_makes_a_membership_change_of_the_parent(): void
    {
        [$playlist] = $this->playlist('audio', ['audio', 'video']);
        $revision = $playlist->concurrencyRevision(PlaylistRevision::TracksOrder);
        $events = ModelEvents::record();
        DB::enableQueryLog();

        $playlist->update(['kind' => 'video']);

        $updates = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $query): bool => str_starts_with($query, 'update ')));
        $column = $playlist->concurrencyRevisionColumn(PlaylistRevision::TracksOrder);
        $previous = array_intersect_key($events->ofClass(Playlist::class)[3][3] ?? [], [$column => true, 'kind' => true]);
        ksort($previous);

        $this->assertCount(1, $updates);
        $this->assertStringStartsWith('update "playlists" set ', $updates[0]);
        $this->assertStringContainsString('"kind" = ?', $updates[0]);
        $this->assertStringContainsString(sprintf('"%1$s" = "%1$s" + 1', $column), $updates[0]);
        $this->assertSame(['saving', 'updating', 'updated', 'saved'], array_column($events->ofClass(Playlist::class), 0));
        $this->assertSame(['kind' => 'audio', $column => $revision], $previous);
        $this->assertSame($revision + 1, $playlist->concurrencyRevision(PlaylistRevision::TracksOrder));
    }

    #[Test]
    public function it_locks_the_parent_before_it_reads_the_members_for_a_membership_change_of_the_parent(): void
    {
        [$playlist] = $this->playlist('audio', ['audio', 'video']);
        DB::enableQueryLog();

        $playlist->update(['kind' => 'video']);

        $queries = array_column(DB::getQueryLog(), 'query');
        $lock = array_find_key($queries, fn (string $query): bool => str_contains($query, 'from "playlists"') && str_ends_with($query, 'for no key update'));
        $write = array_find_key($queries, fn (string $query): bool => str_starts_with($query, 'update "playlists"'));
        $reads = array_keys(array_filter($queries, fn (string $query): bool => str_contains($query, 'from "tracks"')));

        $this->assertIsInt($lock);
        $this->assertIsInt($write);
        $this->assertCount(2, $reads);
        $this->assertTrue($lock < $reads[0] && $reads[0] < $write && $write < $reads[1]);
    }

    private function addVideo(Section $section): Video
    {
        $video = new Video;
        $video->section()->associate($section);
        $video->save();

        return $video;
    }

    private function addClip(Video $video): Clip
    {
        $clip = new Clip;
        $clip->video()->associate($video);
        $clip->save();

        return $clip;
    }

    /**
     * @param  list<string>  $kinds
     * @return array{Playlist, list<Track>}
     */
    private function playlist(string $kind, array $kinds): array
    {
        $playlist = Playlist::create(['kind' => $kind]);

        return [$playlist, array_map(function (string $kind) use ($playlist): Track {
            $track = new Track(['kind' => $kind]);
            $track->playlist()->associate($playlist);
            $track->save();

            return $track;
        }, $kinds)];
    }

    private function tracksRevision(Playlist $playlist): int
    {
        return (int) DB::table('playlists')->where('id', $playlist->id)->value('tracks_order_concurrency_revision');
    }

    /**
     * @return array<int, string>
     */
    private function trackKeys(Playlist $playlist): array
    {
        /** @var array<int, string> */
        return DB::table('tracks')->where('playlist_id', $playlist->id)->orderBy('id')->pluck('rank_key', 'id')->all();
    }

    private function recordReorders(): void
    {
        Event::listen(Reordered::class, function (Reordered $event): void {
            $change = $event->change;
            $this->reorders[] = [
                $change->relation->parent->getKey(),
                $change->order,
                array_map(fn (PositionChange $changed): array => [$changed->id, $changed->position, $changed->previousPosition], $change->changed),
                $change->revision,
            ];
        });
    }
}
