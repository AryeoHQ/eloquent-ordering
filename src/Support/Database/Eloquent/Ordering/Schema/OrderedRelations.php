<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Schema;

use Support\Database\Eloquent\Concurrency\Schema\ConcurrencyRevisions;
use Support\Database\Eloquent\Concurrency\Schema\NamedRevisionTrigger;
use Support\Database\Eloquent\Ordering\OrderedRelation;

final class OrderedRelations
{
    public const string DELETED_AT = 'deleted_at';

    public static function createTrigger(string $table, string $parent, string $foreignKey, string $revision): void
    {
        ConcurrencyRevisions::createNamedTrigger(
            NamedRevisionTrigger::on($table)
                ->increments($parent, $revision)
                ->through($foreignKey)
                ->whenColumnsChange(OrderedRelation::RANK_KEY, $foreignKey, self::DELETED_AT)
                ->whenRowsInsertedOrDeleted(),
        );
    }

    public static function dropTrigger(string $table, string $revision): void
    {
        ConcurrencyRevisions::dropNamedTrigger($table, $revision);
    }
}
