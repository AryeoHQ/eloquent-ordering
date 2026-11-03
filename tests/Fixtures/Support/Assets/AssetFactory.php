<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Assets;

use Illuminate\Database\Eloquent\Factories\Factory;
use Support\Database\Eloquent\Ordering\Testing\PlacesOrderedItems;
use Tests\Fixtures\Support\Sections\Section;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    use PlacesOrderedItems;

    protected $model = Asset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'section_id' => Section::factory(),
        ];
    }
}
