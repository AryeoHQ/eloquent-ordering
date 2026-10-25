<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Providers;

use Illuminate\Support\ServiceProvider;
use Support\Database\Eloquent\Ordering\Schema\Macros;

class Provider extends ServiceProvider
{
    public function boot(): void
    {
        (new Macros)->register();
    }
}
