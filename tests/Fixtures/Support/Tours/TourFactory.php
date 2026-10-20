<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Tours;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tour>
 */
class TourFactory extends Factory
{
    protected $model = Tour::class;

    public function definition(): array
    {
        return [];
    }
}
