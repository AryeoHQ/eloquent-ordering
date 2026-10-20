<?php

declare(strict_types=1);

namespace Support\Ordering\Providers;

use Illuminate\Support\ServiceProvider;
use Support\Ordering\Schema\Macros;

class Provider extends ServiceProvider
{
    public function boot(): void
    {
        (new Macros)->register();
    }
}
