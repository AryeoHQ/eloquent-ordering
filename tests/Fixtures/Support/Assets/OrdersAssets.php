<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Assets;

use Support\Ordering\Placement;
use Tests\Fixtures\Support\Sections\Section;

trait OrdersAssets
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function addAsset(Section $section, Placement|null $placement = null, array $attributes = []): Asset
    {
        $asset = Asset::factory()->for($section)->make($attributes);

        if ($placement !== null) {
            $asset->placeAt($placement);
        }

        $asset->save();

        return $asset;
    }

    /**
     * @param  list<int>  $expected
     */
    protected function assertOrder(array $expected, Section $section): void
    {
        $positions = Asset::query()
            ->withoutGlobalScope(VisibleScope::class)
            ->whereBelongsTo($section)
            ->get()
            ->mapWithKeys(fn (Asset $asset): array => [$asset->id => $asset->position])
            ->sort();

        $this->assertSame(array_map(fn (int $index): int => $index + 1, array_keys($expected)), $positions->values()->all());
        $this->assertSame($expected, $positions->keys()->all());
    }

    /**
     * @return array<int, string>
     */
    protected function rankKeys(Section $section): array
    {
        /** @var array<int, string> */
        return Asset::query()->withoutGlobalScope(VisibleScope::class)->whereBelongsTo($section)->orderBy('id')->pluck('rank_key', 'id')->all();
    }
}
