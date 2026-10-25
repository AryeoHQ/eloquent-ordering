<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\OrderedRelation;

/**
 * @phpstan-require-extends Model
 */
trait ResolvesOrderedRelations
{
    private null|OrderedRelation $resolvedOrderedRelation = null;

    public function orderedRelation(null|NamesConcurrencyRevision $revision = null): OrderedRelation
    {
        if ($revision !== null) {
            $parent = $this instanceof OrdersRelations && $this instanceof Revisioned ? $this : null;

            return new OrderedRelation(
                $parent ?? throw new LogicException(sprintf('[%s] orders no relation.', static::class)),
                $revision,
            );
        }

        throw_unless($this instanceof Ordered, LogicException::class, sprintf('[%s] is not ordered in a relation.', static::class));

        return $this->resolveOrderedRelation($this->getAttributes())
            ?? throw new LogicException(sprintf('[%s:%s] has no parent that orders it.', static::class, $this->getKey()));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveOrderedRelation(array $attributes): null|OrderedRelation
    {
        if (! $this instanceof Ordered) {
            return null;
        }

        $held = array_values(array_filter(
            [...array_values($this->getRelations()), $this->resolvedOrderedRelation?->parent],
            static fn (mixed $model): bool => $model instanceof Model,
        ));

        $relation = OrderedRelation::of($this, $attributes, $held);

        if ($relation !== null) {
            $this->resolvedOrderedRelation = $relation;
        }

        return $relation;
    }
}
