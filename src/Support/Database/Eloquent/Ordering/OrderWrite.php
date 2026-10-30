<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;
use Support\Database\Eloquent\Ordering\Exceptions\WriteHalted;
use WeakMap;

/**
 * @internal
 */
final class OrderWrite
{
    /** @var null|WeakMap<Model, true> */
    private static null|WeakMap $saving = null;

    public static function saves(Model $model): bool
    {
        return self::saving()->offsetExists($model);
    }

    public static function copy(Model $model, bool $timestamps): null|Model
    {
        $copy = $model->newQueryWithoutScopes()->useWritePdo()->whereKey($model->getKey())->first();

        if ($copy !== null) {
            $copy->timestamps = $timestamps;
        }

        return $copy;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, Model>
     */
    public static function copies(Model $template, array $ids, bool $timestamps): array
    {
        $copies = [];

        foreach ($template->newQueryWithoutScopes()->useWritePdo()->whereIn($template->getQualifiedKeyName(), $ids)->get() as $copy) {
            $key = $copy->getKey();

            if (is_int($key) || is_string($key)) {
                $copy->timestamps = $timestamps;
                $copies[$key] = $copy;
            }
        }

        return $copies;
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

            try {
                throw_unless((new ReflectionMethod(Model::class, 'save'))->invoke($copy) === true, WriteHalted::class, $copy);
            } finally {
                unset($saving[$copy]);
            }

            $updatedAt = $copy->usesTimestamps() ? $copy->getUpdatedAtColumn() : null;
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
}
