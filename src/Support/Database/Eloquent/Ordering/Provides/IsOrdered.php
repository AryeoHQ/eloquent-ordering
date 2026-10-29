<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Closure;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;
use RuntimeException;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Exceptions\ContendedOrder;
use Support\Database\Eloquent\Ordering\OrderChange;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Placement;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Ordered
 */
trait IsOrdered
{
    private const int ORDER_LOCK_ATTEMPTS = 5;

    private null|OrderedRelation $resolvedOrderedRelation = null;

    private null|Placement $placement = null;

    private null|int $vacatedPosition = null;

    private null|OrderedRelation $restoredInto = null;

    /** @var null|array{OrderedRelation, OrderedRelation, int} */
    private null|array $reparented = null;

    /** @var list<OrderedRelation> */
    private array $lockedOrderedRelations = [];

    public static function bootIsOrdered(): void
    {
        static::saving(static function (self $item): void {
            $item->restoredInto = null;
            $item->reparented = null;

            if (! $item->exists) {
                $item->lockedOrderedRelations = OrderedRelation::lockAll([$item->resolveOrderedRelation($item->getAttributes())]);
            } elseif ($item->isDirty(OrderedRelation::watchedColumns($item))) {
                $next = OrderedRelation::of($item, $item->getAttributes(), $item->heldOrderedParents());
                [$current, $item->lockedOrderedRelations] = $item->lockCurrentOrderedRelations($next);

                if ($item->getRawOriginal(OrderedRelations::DELETED_AT) !== null && $item->getAttribute(OrderedRelations::DELETED_AT) === null) {
                    $stored = $item->storedOrderedAttributes([OrderedRelation::RANK_KEY, OrderedRelations::DELETED_AT]);

                    if ($stored !== null && $stored[OrderedRelations::DELETED_AT] !== null) {
                        $item->setAttribute(OrderedRelation::RANK_KEY, $next?->keyAt(Placement::last()));
                        $item->restoredInto = $next;
                    } elseif ($stored !== null) {
                        $item->setAttribute(OrderedRelation::RANK_KEY, $stored[OrderedRelation::RANK_KEY]);
                    }
                } elseif ($current !== null && $next !== null && ! $current->is($next) && $item->getAttribute(OrderedRelations::DELETED_AT) === null) {
                    $previous = $current->position($item);

                    if ($previous !== null) {
                        $item->setAttribute(OrderedRelation::RANK_KEY, $next->keyAt(Placement::last()));
                        $item->reparented = [$current, $next, $previous];
                    }
                }
            }
        });

        static::creating(static function (self $item): void {
            $item->setAttribute(OrderedRelation::RANK_KEY, $item->orderingRelation()->keyAt($item->placement ?? Placement::last()));
        });

        static::created(static function (self $item): void {
            $item->placement = null;

            foreach ($item->refreshLockedOrderedRelations() as $relation) {
                $relation->placed($item);
            }
        });

        static::deleting(static function (self $item): void {
            [$current, $item->lockedOrderedRelations] = $item->lockCurrentOrderedRelations();
            $item->vacatedPosition = $current?->position($item);
        });

        static::registerModelEvent('trashed', static function (self $item): void {
            $item->newQueryWithoutScopes()->whereKey($item->getKey())->toBase()->update([OrderedRelation::RANK_KEY => null]);
            $item->setAttribute(OrderedRelation::RANK_KEY, null)->syncOriginalAttribute(OrderedRelation::RANK_KEY);
        });

        static::saved(static function (self $item): void {
            $restored = $item->restoredInto;
            $reparented = $item->reparented;
            $item->restoredInto = null;
            $item->reparented = null;

            $item->refreshLockedOrderedRelations();
            $restored?->placed($item);

            if ($reparented !== null) {
                [$source, $destination, $previous] = $reparented;
                $item->resolvedOrderedRelation = $destination;
                $destination->entered($item, $source, $previous);
            }
        });

        static::deleted(static function (self $item): void {
            $vacated = $item->vacatedPosition;
            $item->vacatedPosition = null;

            foreach ($item->refreshLockedOrderedRelations() as $relation) {
                if ($vacated !== null) {
                    $relation->removed($item, $vacated);
                }
            }
        });
    }

    public function initializeIsOrdered(): void
    {
        $this->makeHidden(OrderedRelation::RANK_KEY);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        return $this->commitUnlessHalted(fn (): bool => parent::save($options));
    }

    public function delete()
    {
        return $this->commitUnlessHalted(fn () => parent::delete());
    }

    public function placeAt(Placement $placement): static
    {
        throw_if($this->exists, LogicException::class, sprintf('[%s:%s] already has a place in its order.', static::class, $this->getKey()));
        throw_if($placement->parent !== null, LogicException::class, sprintf('Associate the parent of a new [%s] rather than name it in the placement.', static::class));

        $this->placement = $placement;

        return $this;
    }

    public function move(Placement $placement, null|int $expected): OrderChange
    {
        throw_unless($this->exists, LogicException::class, sprintf('[%s] must be saved before it moves.', static::class));

        return $this->getConnection()->transaction(function () use ($placement, $expected): OrderChange {
            $destination = $placement->parent === null ? null : OrderedRelation::in($placement->parent, $this);
            [$source] = $this->lockCurrentOrderedRelations($destination);

            if ($source === null) {
                throw new LogicException(sprintf('[%s:%s] has no parent that orders it.', static::class, $this->getKey()));
            }

            if ($expected !== null) {
                $read = $this->resolveOrderedRelation($this->getRawOriginal());

                if ($read === null || ! $read->is($source)) {
                    throw new StaleConcurrencyRevision(($read ?? $source)->parent, ($read ?? $source)->revision, $expected);
                }

                ($destination ?? $source)->compareConcurrencyRevision($expected);
            }

            $change = ($destination ?? $source)->move($this, $placement, $source);

            if ($change->source !== null) {
                foreach ($this->getRelations() as $name => $related) {
                    if ($related !== $source->parent) {
                        continue;
                    }

                    if ($related::class === $change->relation->parent::class) {
                        $this->setRelation($name, $change->relation->parent);
                    } else {
                        $this->unsetRelation($name);
                    }
                }

                if ($this instanceof Pivot && $this->pivotParent === $source->parent) {
                    $this->pivotParent = $change->relation->parent;
                }
            }

            $this->resolvedOrderedRelation = $change->relation;

            return $change;
        });
    }

    public function orderingRelation(): OrderedRelation
    {
        return $this->resolveOrderedRelation($this->getAttributes())
            ?? throw new LogicException(sprintf('[%s:%s] has no parent that orders it.', static::class, $this->getKey()));
    }

    /**
     * @return Attribute<null|int, never>
     */
    protected function position(): Attribute
    {
        return Attribute::make(get: fn (): null|int => $this->exists
            ? $this->resolveOrderedRelation($this->getAttributes())?->position($this)
            : null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveOrderedRelation(array $attributes): null|OrderedRelation
    {
        $relation = OrderedRelation::of($this, $attributes, $this->heldOrderedParents());

        if ($relation !== null) {
            $this->resolvedOrderedRelation = $relation;
        }

        return $relation;
    }

    /**
     * @return list<Model>
     */
    private function heldOrderedParents(): array
    {
        return array_values(array_filter(
            [...array_values($this->getRelations()), $this->resolvedOrderedRelation?->parent],
            static fn (mixed $model): bool => $model instanceof Model,
        ));
    }

    /**
     * @return array{null|OrderedRelation, list<OrderedRelation>}
     */
    private function lockCurrentOrderedRelations(null|OrderedRelation $next = null): array
    {
        $moved = new RuntimeException;

        for ($attempt = 1; $attempt <= self::ORDER_LOCK_ATTEMPTS; $attempt++) {
            $stored = $this->storedOrderedAttributes(OrderedRelation::membershipColumns($this));
            $current = $stored === null ? null : $this->resolveOrderedRelation($stored);

            try {
                return $this->getConnection()->transaction(function () use ($stored, $current, $next, $moved): array {
                    $locked = OrderedRelation::lockAll([$current, $next]);

                    throw_unless($this->storedOrderedAttributes(OrderedRelation::membershipColumns($this)) === $stored, $moved);

                    return [$current, $locked];
                });
            } catch (RuntimeException $exception) {
                throw_unless($exception === $moved, $exception);
            }
        }

        throw new ContendedOrder($this, self::ORDER_LOCK_ATTEMPTS);
    }

    /**
     * @param  list<string>  $columns
     * @return null|array<string, mixed>
     */
    private function storedOrderedAttributes(array $columns): null|array
    {
        $stored = $this->newQueryWithoutScopes()->whereKey($this->getKey())->toBase()->useWritePdo()->first($columns);

        return $stored === null ? null : (array) $stored;
    }

    /**
     * @return list<OrderedRelation>
     */
    private function refreshLockedOrderedRelations(): array
    {
        $relations = $this->lockedOrderedRelations;

        foreach ($relations as $relation) {
            $relation->refreshConcurrencyRevision();
        }

        $this->lockedOrderedRelations = [];

        return $relations;
    }

    /**
     * @template TResult of null|bool|int
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    private function commitUnlessHalted(Closure $write): null|bool|int
    {
        $halted = new RuntimeException;
        $result = null;

        try {
            return $this->getConnection()->transaction(function () use ($write, $halted, &$result): null|bool|int {
                $result = $write();

                throw_if($result === false, $halted);

                return $result;
            });
        } catch (RuntimeException $exception) {
            throw_unless($exception === $halted, $exception);

            return $result;
        }
    }
}
