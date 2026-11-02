<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Tests\Fixtures\Support\Assets\Asset;

require __DIR__.'/../../../../vendor/autoload.php';

[, $section, $lock] = $_SERVER['argv'];

Application::create(options: ['extra' => ['providers' => [Provider::class]]]);

$page = Asset::query()->where('section_id', $section)->orderBy('id')->get();

DB::select('select pg_advisory_lock(?)', [(int) $lock]);

echo json_encode($page->map(static fn (Asset $asset): array => [$asset->id, $asset->position])->all());
