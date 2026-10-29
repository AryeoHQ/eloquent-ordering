<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Support\Database\Eloquent\Ordering\Events\Rebalanced;
use Support\Database\Eloquent\Ordering\Exceptions\WriteHalted;
use Throwable;
use WeakMap;

/**
 * @internal
 */
final class OrderWrite
{
    /** @var list<self> */
    private static array $writes = [];

    /** @var null|WeakMap<Model, true> */
    private static null|WeakMap $replaying = null;

    /** @var null|WeakMap<Throwable, true> */
    private static null|WeakMap $failedReplays = null;

    /** @var array<class-string, list<ReflectionProperty>> */
    private static array $properties = [];

    /** @var array<string, array{Model, array<int|string, array<string, mixed>>}> */
    private array $items = [];

    /** @var array<string, array{OrderedRelation, array<string, mixed>}> */
    private array $parents = [];

    /** @var array<string, array{OrderedRelation, list<int|string>}> */
    private array $rebalanced = [];

    /** @var array<int, array{Model, list<array{ReflectionProperty, mixed}>}> */
    private array $kept = [];

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public static function run(Model $owner, Closure $write): mixed
    {
        $context = new self;
        $context->keep($owner);
        self::$writes[] = $context;

        try {
            return $owner->getConnection()->transaction(function () use ($context, $write): mixed {
                $result = $write();
                $context->replay();

                return $result;
            });
        } catch (Throwable $exception) {
            if (self::failedReplays()->offsetExists($exception)) {
                $context->restore();
            }

            throw $exception;
        } finally {
            array_pop(self::$writes);
        }
    }

    public static function replays(Model $model): bool
    {
        return self::replaying()->offsetExists($model);
    }

    /**
     * @param  list<mixed>  $ids
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function update(Model $item, array $ids, array $attributes): array
    {
        self::current()?->record($item, $ids);

        $column = $item->usesTimestamps() ? $item->getUpdatedAtColumn() : null;

        if ($column !== null) {
            $attributes[$column] = $item->freshTimestampString();
        }

        $item->newQueryWithoutScopes()->toBase()->whereIn($item->getQualifiedKeyName(), $ids)->update($attributes);

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function locked(OrderedRelation $relation, array $row): void
    {
        $write = self::current();

        if ($write === null) {
            return;
        }

        $write->keep($relation->parent);

        if ($row !== []) {
            $write->parents[self::parentKey($relation->parent)] = [$relation, $row];
        }
    }

    /**
     * @param  list<OrderedRelation>  $relations
     */
    public static function released(array $relations): void
    {
        $write = self::current();

        if ($write === null) {
            return;
        }

        foreach ($relations as $relation) {
            unset($write->parents[self::parentKey($relation->parent)]);
        }
    }

    /**
     * @param  list<int|string>  $ids
     */
    public static function rebalanced(OrderedRelation $relation, array $ids): void
    {
        $write = self::current();

        if ($write === null || $ids === []) {
            return;
        }

        $key = self::parentKey($relation->parent).':'.$relation->revision->value;
        $write->rebalanced[$key] = [$relation, array_values(array_unique([...$write->rebalanced[$key][1] ?? [], ...$ids]))];
    }

    public static function announcing(OrderChange $change): void
    {
        $write = self::current();

        if ($write === null) {
            return;
        }

        foreach ($write->rebalanced as $key => [$relation, $ids]) {
            if ($relation->is($change->relation)) {
                unset($write->rebalanced[$key]);
                event(new Rebalanced($change->relation, $change->revision, $ids));
            }
        }
    }

    private static function current(): null|self
    {
        $last = array_key_last(self::$writes);

        return $last === null ? null : self::$writes[$last];
    }

    /**
     * @return WeakMap<Model, true>
     */
    private static function replaying(): WeakMap
    {
        return self::$replaying ??= new WeakMap;
    }

    /**
     * @return WeakMap<Throwable, true>
     */
    private static function failedReplays(): WeakMap
    {
        return self::$failedReplays ??= new WeakMap;
    }

    private static function parentKey(Model $parent): string
    {
        return $parent->getMorphClass().':'.self::id($parent->getKey());
    }

    private static function id(mixed $key): string
    {
        throw_unless(is_int($key) || is_string($key), LogicException::class, 'An ordered row has no key.');

        return (string) $key;
    }

    /**
     * @param  list<mixed>  $ids
     * @return array<int|string, array<string, mixed>>
     */
    private static function rows(Model $item, array $ids): array
    {
        $rows = [];

        foreach ($item->newQueryWithoutScopes()->toBase()->useWritePdo()->whereIn($item->getQualifiedKeyName(), $ids)->get() as $row) {
            /** @var array<string, mixed> $row */
            $row = (array) $row;
            $rows[self::id($row[$item->getKeyName()] ?? null)] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array{ReflectionProperty, mixed}>
     */
    private static function snapshot(Model $model): array
    {
        if (! array_key_exists($model::class, self::$properties)) {
            $properties = [];

            for ($class = new ReflectionClass($model); $class !== false; $class = $class->getParentClass()) {
                foreach ($class->getProperties() as $property) {
                    if ($property->class === $class->name && ! $property->isStatic() && ! $property->isReadOnly() && ! $property->isVirtual()) {
                        $properties[] = $property;
                    }
                }
            }

            self::$properties[$model::class] = $properties;
        }

        return array_values(array_map(
            static fn (ReflectionProperty $property): array => [$property, $property->getValue($model)],
            array_filter(self::$properties[$model::class], static fn (ReflectionProperty $property): bool => $property->isInitialized($model)),
        ));
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private static function fire(Model $template, array $before, array $after): void
    {
        $model = $template->newInstance([], true)->setRawAttributes($before, true)->setRawAttributes($after);
        $replaying = self::replaying();
        $replaying[$model] = true;

        try {
            foreach (['saving', 'updating'] as $event) {
                throw_if(self::dispatch($model, $event, true) === false, WriteHalted::class, $model, $event);
            }

            $changed = self::changed($model, $after);

            throw_unless($changed === [], LogicException::class, sprintf(
                'A listener changed [%s] of [%s:%s] while an order write replayed its events. A replayed [saving] or [updating] event cannot change the row.',
                implode(', ', $changed),
                $model::class,
                $model->getKey(),
            ));

            $model->syncChanges();
            self::dispatch($model, 'updated', false);
            self::dispatch($model, 'saved', false);
            $model->syncOriginal();
        } finally {
            unset($replaying[$model]);
        }
    }

    private static function dispatch(Model $model, string $event, bool $halt): mixed
    {
        return (new ReflectionMethod($model, 'fireModelEvent'))->invoke($model, $event, $halt);
    }

    /**
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private static function changed(Model $model, array $after): array
    {
        $attributes = $model->getAttributes();
        $probe = $model->newInstance([], true)->setRawAttributes($after, true)->setRawAttributes($attributes);

        return array_map(strval(...), array_keys([...array_diff_key($after, $attributes), ...$probe->getDirty()]));
    }

    private function keep(Model $model): void
    {
        $this->kept[spl_object_id($model)] ??= [$model, self::snapshot($model)];
    }

    private function restore(): void
    {
        foreach ($this->kept as [$model, $snapshot]) {
            foreach ($snapshot as [$property, $value]) {
                $property->setValue($model, $value);
            }
        }
    }

    /**
     * @param  list<mixed>  $ids
     */
    private function record(Model $item, array $ids): void
    {
        $group = $item::class.':'.$item->getTable();
        $read = $this->items[$group][1] ?? [];
        $unread = array_values(array_filter($ids, static fn (mixed $id): bool => ! array_key_exists(self::id($id), $read)));

        if ($unread === []) {
            return;
        }

        $rows = self::rows($item, $unread);

        foreach ($unread as $id) {
            if (array_key_exists(self::id($id), $rows)) {
                $read[self::id($id)] = $rows[self::id($id)];
            }
        }

        $this->items[$group] = [$this->items[$group][0] ?? $item, $read];
    }

    private function replay(): void
    {
        try {
            $replays = [];

            foreach ($this->items as [$item, $before]) {
                $after = self::rows($item, array_keys($before));

                foreach ($before as $id => $row) {
                    if (array_key_exists($id, $after) && $after[$id] !== $row) {
                        $replays[] = [$item, $row, $after[$id]];
                    }
                }
            }

            foreach ($this->parents as [$relation, $before]) {
                $column = $relation->parent->concurrencyRevisionColumn($relation->revision);
                $after = self::rows($relation->parent, [$relation->parent->getKey()])[self::id($relation->parent->getKey())] ?? null;

                if ($after !== null && ($after[$column] ?? null) !== ($before[$column] ?? null)) {
                    $replays[] = [$relation->parent, $before, $after];
                }
            }

            $rebalanced = array_map(
                static fn (array $rebalance): Rebalanced => new Rebalanced($rebalance[0], $rebalance[0]->concurrencyRevision(), $rebalance[1]),
                array_values($this->rebalanced),
            );

            foreach ($replays as [$template, $before, $after]) {
                self::fire($template, $before, $after);
            }

            $this->rebalanced = [];

            foreach ($rebalanced as $event) {
                event($event);
            }
        } catch (Throwable $exception) {
            self::failedReplays()[$exception] = true;

            throw $exception;
        }
    }
}
