<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Videos;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Fixtures\Support\Sections\Section;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    protected $model = Video::class;

    public function definition(): array
    {
        return [
            'section_id' => Section::factory(),
        ];
    }
}
