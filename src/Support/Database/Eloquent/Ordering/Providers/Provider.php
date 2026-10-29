<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Schema\Macros;

class Provider extends ServiceProvider
{
    public function boot(): void
    {
        (new Macros)->register();

        Builder::macro('withPositions', function (): Builder {
            throw new LogicException(sprintf('[withPositions()] numbers only a query that [%s::query()] returns.', OrderedRelation::class));
        });
    }
}
