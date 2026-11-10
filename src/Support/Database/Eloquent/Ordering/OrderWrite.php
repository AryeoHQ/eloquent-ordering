<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Exceptions\WriteHalted;
use Support\Database\Eloquent\Ordering\Scopes\SelectsPosition;
use WeakMap;

/**
 * @internal
 */
final class OrderWrite
{
    /** @var null|WeakMap<Model, true> */
    private static null|WeakMap $saving = null;

    /** @var null|WeakMap<Model, true> */
    private static null|WeakMap $untimed = null;

    public static function saves(Model $model): bool
    {
        return self::saving()->offsetExists($model);
    }

    public static function copy(Model $model, bool $timestamps): null|Model
    {
        $copy = $model->newQueryWithoutScopes()->withoutGlobalScope(SelectsPosition::class)->useWritePdo()->whereKey($model->getKey())->first();

        return $copy === null ? null : self::timestamps($copy, $timestamps);
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, Model>
     */
    public static function copies(Model $template, array $ids, bool $timestamps): array
    {
        $copies = [];

        foreach ($template->newQueryWithoutScopes()->withoutGlobalScope(SelectsPosition::class)->useWritePdo()->whereIn($template->getQualifiedKeyName(), $ids)->get() as $copy) {
            $key = $copy->getKey();

            if (is_int($key) || is_string($key)) {
                $copies[$key] = self::timestamps($copy, $timestamps);
            }
        }

        return $copies;
    }

    public static function timestamps(Model $copy, bool $timestamps): Model
    {
        if ($timestamps) {
            $copy->timestamps = true;
            unset(self::untimed()[$copy]);
        } else {
            self::untimed()[$copy] = true;
        }

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function save(Model $copy, array $attributes): array
    {
        $columns = array_keys($attributes);

        if ($copy->forceFill($attributes)->isDirty()) {
            $saving = self::saving();
            $saving[$copy] = true;

            if ($copy instanceof Ordered) {
                OrderedRelation::prepared($copy);
            }

            $timed = ! self::untimed()->offsetExists($copy);
            $save = static fn (): mixed => (new ReflectionMethod(Model::class, 'save'))->invoke($copy, ['touch' => false]);

            try {
                throw_unless(($timed ? $save() : Model::withoutTimestampsOn([$copy::class], $save)) === true, WriteHalted::class, $copy);
            } finally {
                unset($saving[$copy]);
            }

            $updatedAt = $timed && $copy->usesTimestamps() ? $copy->getUpdatedAtColumn() : null;
            $columns = $updatedAt === null ? $columns : [...$columns, $updatedAt];
        }

        return array_intersect_key($copy->getAttributes(), array_flip($columns));
    }

    /**
     * @return WeakMap<Model, true>
     */
    private static function saving(): WeakMap
    {
        return self::$saving ??= new WeakMap;
    }

    /**
     * @return WeakMap<Model, true>
     */
    private static function untimed(): WeakMap
    {
        return self::$untimed ??= new WeakMap;
    }
}
