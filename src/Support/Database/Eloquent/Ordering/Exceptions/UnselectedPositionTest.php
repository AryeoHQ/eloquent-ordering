<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Exceptions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Conferences\Conference;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Panels\Panel;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Shapes\PivotShape;
use Tests\Fixtures\Support\Shapes\Shape;
use Tests\Fixtures\Support\Speakers\Speaker;
use Tests\TestCase;

#[CoversClass(UnselectedPosition::class)]
class UnselectedPositionTest extends TestCase
{
    /**
     * @return iterable<string, list<mixed>>
     */
    public static function unselectedReads(): iterable
    {
        return Shape::crossed([
            'withoutPositions()' => [fn (Shape $shape, Model $member): Model => $shape->memberClass()::query()->withoutPositions()->findOrFail($member->getKey())],
            'an explicit select list' => [fn (Shape $shape, Model $member): Model => $shape->memberClass()::query()->select([$member->getKeyName(), $shape->foreignKey()])->findOrFail($member->getKey())],
            'explicit columns' => [fn (Shape $shape, Model $member): Model => $shape->memberClass()::query()->findOrFail($member->getKey(), [$member->getQualifiedKeyName()])],
            'a select list of every column and another' => [fn (Shape $shape, Model $member): Model => $shape->memberClass()::query()->select([$member->qualifyColumn('*'), $member->qualifyColumn('id').' as copied_id'])->findOrFail($member->getKey())],
        ]);
    }

    /**
     * @param  Closure(Shape, Model): Model  $read
     */
    #[DataProvider('unselectedReads')]
    #[Test]
    public function it_names_the_item_whose_query_selected_no_position(Shape $shape, Closure $read): void
    {
        [, [$member]] = $shape->with(1);
        $loaded = $read($shape, $member);

        try {
            $loaded->getAttribute('position');
            $this->fail('A position that no query selected was read.');
        } catch (UnselectedPosition $exception) {
            $this->assertSame($loaded, $exception->item);
            $this->assertStringStartsWith(sprintf('[%s:%s] was read without its position.', $loaded::class, $loaded->getKey()), $exception->getMessage());
        }
    }

    #[DataProvider('pivots')]
    #[Test]
    public function it_names_a_pivot_whose_relation_query_selected_no_position(PivotShape $shape): void
    {
        [$parent] = $shape->with(1);
        $customer = $shape->customers($parent)->withoutPositions()->get()->firstOrFail();
        assert($customer instanceof Customer);
        $pivot = $shape->item($customer);

        $this->expectException(UnselectedPosition::class);

        $pivot->getAttribute('position');
    }

    /**
     * @return iterable<string, array{PivotShape}>
     */
    public static function pivots(): iterable
    {
        return PivotShape::pivots();
    }

    /**
     * @return iterable<string, array{Closure(): mixed, string}>
     */
    public static function namedShapes(): iterable
    {
        yield 'a model with $withCount' => [fn (): mixed => (new class extends Asset
        {
            protected $table = 'assets';

            protected $withCount = ['section'];
        })->newQuery()->findOrFail(self::asset()->id)->getAttribute('position'), '$withCount'];

        yield 'withoutGlobalScopes()' => [fn (): mixed => Asset::query()->withoutGlobalScopes()->findOrFail(self::asset()->id)->position, 'withoutGlobalScopes()'];

        yield 'paginate() on a BelongsToMany relation' => [function (): mixed {
            $conference = Conference::create();
            $conference->speakers()->attach(Speaker::query()->create(['panel_id' => Panel::create()->id])->id);

            return $conference->speakers()->paginate()->getCollection()->firstOrFail()->getAttribute('position');
        }, 'paginate()'];

        yield 'a for key share lock' => [fn (): mixed => DB::transaction(fn (): mixed => Asset::query()->lock('for key share')->findOrFail(self::asset()->id)->position), 'for key share'];

        yield 'isset()' => [fn (): bool => isset(Asset::query()->select(['assets.id'])->findOrFail(self::asset()->id)->position), 'isset()']; // @phpstan-ignore ergebnis.noIsset

        yield '??' => [fn (): int => Asset::query()->select(['assets.id'])->findOrFail(self::asset()->id)->position ?? 0, '??'];
    }

    /**
     * @param  Closure(): mixed  $read
     */
    #[DataProvider('namedShapes')]
    #[Test]
    public function it_names_each_shape_whose_query_selects_no_position(Closure $read, string $shape): void
    {
        try {
            $read();
            $this->fail("{$shape} read a position that its query never selected.");
        } catch (UnselectedPosition $exception) {
            $this->assertStringContainsString($shape, $exception->getMessage());
        }
    }

    private static function asset(): Asset
    {
        $asset = new Asset;
        $asset->section()->associate(Section::create());
        $asset->save();

        return $asset;
    }
}
