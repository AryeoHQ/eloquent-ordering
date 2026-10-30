<?php

use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tooling\Rector\Rules\AddInterfaceByTrait;

return [
    AddInterfaceByTrait::class => [
        HasOrderedRelations::class => OrdersRelations::class,
        IsOrdered::class => Ordered::class,
    ],
];
