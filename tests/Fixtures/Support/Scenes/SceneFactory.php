<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Scenes;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Fixtures\Support\Tours\Tour;

/**
 * @extends Factory<Scene>
 */
class SceneFactory extends Factory
{
    protected $model = Scene::class;

    public function definition(): array
    {
        return [
            'tour_id' => Tour::factory(),
        ];
    }
}
