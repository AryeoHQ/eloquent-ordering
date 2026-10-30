<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class ModelEvents
{
    private const array RECORDED = ['saving', 'updating', 'updated', 'saved'];

    /** @var list<array{string, class-string, mixed, array<string, mixed>, array<string, mixed>}> */
    public array $fired = [];

    public static function record(): self
    {
        $events = new self;

        Event::listen('eloquent.*', static function (string $name, array $payload) use ($events): void {
            $event = explode(':', substr($name, strlen('eloquent.')), 2)[0];
            $model = $payload[0] ?? null;

            if (! $model instanceof Model || ! in_array($event, self::RECORDED, true)) {
                return;
            }

            $events->fired[] = in_array($event, ['saving', 'updating'], true)
                ? [$event, $model::class, $model->getKey(), array_intersect_key($model->getRawOriginal(), $model->getDirty()), $model->getDirty()]
                : [$event, $model::class, $model->getKey(), $model->getPrevious(), $model->getChanges()];
        });

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Model $model): array
    {
        /** @var array<string, mixed> */
        return (array) DB::table($model->getTable())->where($model->getKeyName(), $model->getKey())->first();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{string, class-string, mixed, array<string, mixed>, array<string, mixed>}>
     */
    public static function saveEvents(Model $model, array $before, array $after): array
    {
        $changed = array_keys(array_filter($after, static fn (mixed $value, string $column): bool => ! array_key_exists($column, $before) || $before[$column] !== $value, ARRAY_FILTER_USE_BOTH));
        $changes = [array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed))];

        return array_map(static fn (string $event): array => [$event, $model::class, $model->getKey(), ...$changes], self::RECORDED);
    }

    /**
     * @return list<array{string, class-string, mixed, array<string, mixed>, array<string, mixed>}>
     */
    public function of(Model $model): array
    {
        return array_values(array_filter(
            $this->fired,
            static fn (array $fired): bool => $fired[1] === $model::class && $fired[2] === $model->getKey(),
        ));
    }

    /**
     * @param  class-string  $class
     * @return list<array{string, class-string, mixed, array<string, mixed>, array<string, mixed>}>
     */
    public function ofClass(string $class): array
    {
        return array_values(array_filter($this->fired, static fn (array $fired): bool => $fired[1] === $class));
    }
}
