<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Events;

use Illuminate\Database\Eloquent\Model;

final readonly class ModelUpdated
{
    public function __construct(public Model $model) {}
}
