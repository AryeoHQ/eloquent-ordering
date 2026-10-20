<?php

declare(strict_types=1);

namespace Support\Ordering\Schema;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Support\Ordering\OrderedCollection;

final class Macros
{
    public function register(): void
    {
        Blueprint::macro('rankKey', function (string $column = 'rank_key'): ColumnDefinition {
            $definition = $this->string($column, OrderedCollection::MAX_KEY_LENGTH);
            $driver = Closure::bind(fn (): string => $this->connection->getDriverName(), $this, Blueprint::class);

            return match ($driver()) {
                'pgsql' => $definition->collation('C'),
                'mysql', 'mariadb' => $definition->collation('utf8mb4_bin'),
                default => $definition,
            };
        });

        Blueprint::macro('orderVersion', function (string $collection): ColumnDefinition {
            return $this->unsignedBigInteger(OrderedCollection::versionColumn($collection))->default(0);
        });
    }
}
