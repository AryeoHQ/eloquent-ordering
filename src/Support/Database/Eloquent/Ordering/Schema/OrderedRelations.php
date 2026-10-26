<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Schema;

use Illuminate\Support\Facades\Schema;
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
                ->whenColumnsChange(...self::watchedColumns($table))
                ->whenRowsInsertedOrDeleted(),
        );
    }

    public static function createMorphTrigger(string $table, string $parent, string $morphName, string $morphType, string $revision): void
    {
        ConcurrencyRevisions::createNamedTrigger(
            NamedRevisionTrigger::on($table)
                ->increments($parent, $revision)
                ->throughMorph($morphName, $morphType)
                ->whenColumnsChange(...self::watchedColumns($table))
                ->whenRowsInsertedOrDeleted(),
        );
    }

    public static function dropTrigger(string $table, string $revision): void
    {
        ConcurrencyRevisions::dropNamedTrigger($table, $revision);
    }

    /**
     * @return list<string>
     */
    private static function watchedColumns(string $table): array
    {
        return [OrderedRelation::RANK_KEY, ...Schema::hasColumn($table, self::DELETED_AT) ? [self::DELETED_AT] : []];
    }
}
