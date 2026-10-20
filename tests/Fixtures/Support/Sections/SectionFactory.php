<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Sections;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    protected $model = Section::class;

    public function definition(): array
    {
        return [];
    }
}
