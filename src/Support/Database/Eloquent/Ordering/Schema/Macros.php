<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Support\Database\Eloquent\Ordering\OrderedRelation;

final class Macros
{
    public function register(): void
    {
        Blueprint::macro('rankKey', function (): ColumnDefinition {
            return $this->string(OrderedRelation::RANK_KEY, OrderedRelation::MAX_KEY_LENGTH)->collation('C')->nullable();
        });
    }
}
