<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;
use InvalidArgumentException;
use Support\Database\Eloquent\Ordering\OrderedRelation;

final class Macros
{
    public function register(): void
    {
        Blueprint::macro('rankKey', function (): ColumnDefinition {
            return $this->string(OrderedRelation::RANK_KEY, OrderedRelation::MAX_KEY_LENGTH)->collation('C')->nullable();
        });

        Blueprint::macro('rankKeyIndex', function (string ...$membershipColumns): Fluent {
            throw_if($membershipColumns === [], InvalidArgumentException::class, sprintf(
                'rankKeyIndex() on [%s] needs the parent columns of the membership, such as rankKeyIndex(\'section_id\'), because a rank key is unique only within one order.',
                $this->getTable(),
            ));

            $columns = [...array_values($membershipColumns), OrderedRelation::RANK_KEY];

            return $this->addCommand('rankKeyIndex', ['index' => $this->createIndexName('unique', $columns), 'columns' => $columns]); // @phpstan-ignore method.protected, method.protected
        });

        Grammar::macro('compileRankKeyIndex', function (Blueprint $blueprint, Fluent $command): string {
            $added = array_map(static fn (ColumnDefinition $column): mixed => $column->get('name'), $blueprint->getAddedColumns());
            $live = in_array(OrderedRelations::DELETED_AT, $added, true)
                || (! $blueprint->creating() && $this->connection->getSchemaBuilder()->hasColumn($blueprint->getTable(), OrderedRelations::DELETED_AT)); // @phpstan-ignore property.protected

            return sprintf(
                'create unique index %s on %s (%s)%s',
                $this->wrap($command->string('index')->toString()),
                $this->wrapTable($blueprint),
                $this->columnize($command->array('columns')),
                $live ? sprintf(' where %s is null', $this->wrap(OrderedRelations::DELETED_AT)) : '',
            );
        });
    }
}
