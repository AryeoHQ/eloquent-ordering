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

    /**
     * @param  list<string>  $membership
     */
    public static function createTrigger(string $table, string $parent, string $foreignKey, string $revision, array $membership = [], null|string $relatedKey = null): void
    {
        ConcurrencyRevisions::createNamedTrigger(
            NamedRevisionTrigger::on($table)
                ->increments($parent, $revision)
                ->through($foreignKey)
                ->whenColumnsChange(...self::watchedColumns($table, $relatedKey), ...$membership)
                ->whenRowsInsertedOrDeleted(),
        );
    }

    /**
     * @param  list<string>  $membership
     */
    public static function createMorphTrigger(string $table, string $parent, string $morphName, string $morphType, string $revision, array $membership = [], null|string $relatedKey = null): void
    {
        ConcurrencyRevisions::createNamedTrigger(
            NamedRevisionTrigger::on($table)
                ->increments($parent, $revision)
                ->throughMorph($morphName, $morphType)
                ->whenColumnsChange(...self::watchedColumns($table, $relatedKey), ...$membership)
                ->whenRowsInsertedOrDeleted(),
        );
    }

    /**
     * @param  list<string>  $membership
     */
    public static function createParentTrigger(string $table, string $revision, array $membership): void
    {
        ConcurrencyRevisions::createNamedTrigger(
            NamedRevisionTrigger::on($table)
                ->increments($table, $revision)
                ->onParent()
                ->whenColumnsChange(...$membership),
        );
    }

    /**
     * @param  list<string>  $membership
     */
    public static function createRelatedTrigger(string $table, string $parent, string $pivot, string $relatedKey, string $foreignKey, string $revision, array $membership = []): void
    {
        ConcurrencyRevisions::createNamedTrigger(
            NamedRevisionTrigger::on($table)
                ->increments($parent, $revision)
                ->throughPivot($pivot, $relatedKey, $foreignKey)
                ->whenColumnsChange(...self::softDeleteColumn($table), ...$membership),
        );
    }

    /**
     * @param  list<string>  $membership
     */
    public static function createRelatedMorphTrigger(string $table, string $parent, string $pivot, string $relatedKey, string $morphName, string $morphType, string $revision, array $membership = []): void
    {
        ConcurrencyRevisions::createNamedTrigger(
            NamedRevisionTrigger::on($table)
                ->increments($parent, $revision)
                ->throughMorphPivot($pivot, $relatedKey, $morphName, $morphType)
                ->whenColumnsChange(...self::softDeleteColumn($table), ...$membership),
        );
    }

    public static function dropTrigger(string $table, string $revision): void
    {
        ConcurrencyRevisions::dropNamedTrigger($table, $revision);
    }

    /**
     * @return list<string>
     */
    private static function watchedColumns(string $table, null|string $relatedKey): array
    {
        return [OrderedRelation::RANK_KEY, ...self::softDeleteColumn($table), ...$relatedKey === null ? [] : [$relatedKey]];
    }

    /**
     * @return list<string>
     */
    private static function softDeleteColumn(string $table): array
    {
        return Schema::hasColumn($table, self::DELETED_AT) ? [self::DELETED_AT] : [];
    }
}
