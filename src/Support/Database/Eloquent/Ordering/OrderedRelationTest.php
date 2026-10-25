<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\TestCase;

#[CoversClass(OrderedRelation::class)]
class OrderedRelationTest extends TestCase
{
    #[Test]
    public function it_queries_the_members_in_order(): void
    {
        $section = Section::create();
        $third = $this->addAsset($section);
        $first = $this->addAsset($section, Placement::first());
        $second = $this->addAsset($section, Placement::after($first->id));
        $this->addAsset(Section::create());

        $this->assertSame([$first->id, $second->id, $third->id], $this->members($section));
    }

    #[Test]
    public function it_queries_through_the_parents_relation(): void
    {
        $section = Section::create();

        $query = $section->orderedRelation(SectionRevision::AssetsOrder)->query();

        $this->assertInstanceOf(HasMany::class, $query);
        $this->assertSame($section, $query->getParent());
    }

    #[Test]
    public function it_drops_every_global_scope_except_soft_deletes(): void
    {
        $section = Section::create();
        $hidden = $this->addAsset($section, attributes: ['visible' => false]);
        $trashed = $this->addAsset($section);
        $visible = $this->addAsset($section);

        $trashed->delete();

        $this->assertSame([$hidden->id, $visible->id], $this->members($section));
    }

    #[Test]
    public function it_reads_the_concurrency_revision_from_the_parent_row(): void
    {
        $section = Section::create();

        DB::table('sections')->where('id', $section->id)->update(['assets_order_concurrency_revision' => 7]);

        $this->assertSame(7, $section->orderedRelation(SectionRevision::AssetsOrder)->concurrencyRevision());
        $this->assertSame(1, $section->concurrencyRevision(SectionRevision::AssetsOrder));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function addAsset(Section $section, null|Placement $placement = null, array $attributes = []): Asset
    {
        $asset = new Asset($attributes);
        $asset->section()->associate($section);

        if ($placement !== null) {
            $asset->placeAt($placement);
        }

        $asset->save();

        return $asset;
    }

    /**
     * @return list<int>
     */
    private function members(Section $section): array
    {
        /** @var list<int> */
        return $section->orderedRelation(SectionRevision::AssetsOrder)->query()->pluck('id')->all();
    }
}
