<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Clips\Clip;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Customers\Customerable;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Videos\Video;
use Tests\Fixtures\Support\Videos\VideoRevision;
use Tests\TestCase;

#[CoversClass(OrdersRelation::class)]
#[CoversTrait(HasOrderedRelations::class)]
#[CoversTrait(ResolvesOrderedRelations::class)]
class HasOrderedRelationsTest extends TestCase
{
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

        $item = $video->orderedRelation();
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
    public function it_rejects_the_item_side_of_a_model_that_is_not_ordered(): void
    {
        $this->expectException(LogicException::class);

        Section::create()->orderedRelation();
    }

    #[Test]
    public function it_rejects_the_parent_side_of_a_model_that_orders_nothing(): void
    {
        $this->expectException(LogicException::class);

        (new Asset)->orderedRelation(SectionRevision::AssetsOrder);
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

    private function addVideo(Section $section): Video
    {
        $video = new Video;
        $video->section()->associate($section);
        $video->save();

        return $video;
    }
}
