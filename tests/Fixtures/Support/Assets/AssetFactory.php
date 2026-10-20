<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Assets;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Fixtures\Support\Sections\Section;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'section_id' => Section::factory(),
        ];
    }

    public function hidden(): static
    {
        return $this->state([
            'visible' => false,
        ]);
    }
}
