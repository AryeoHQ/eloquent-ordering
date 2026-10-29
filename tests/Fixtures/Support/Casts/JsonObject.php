<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * @implements CastsAttributes<null|stdClass, mixed>
 */
final class JsonObject implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): null|stdClass
    {
        return is_string($value) ? (object) json_decode($value, true) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): null|string
    {
        return $value === null ? null : (string) json_encode($value);
    }
}
