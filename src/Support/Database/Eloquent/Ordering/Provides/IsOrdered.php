<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Closure;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use RuntimeException;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Exceptions\ContendedOrder;
use Support\Database\Eloquent\Ordering\OrderChange;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\OrderWrite;
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

    /** @var null|array{null|OrderedRelation, null|OrderedRelation, null|int} */
    private null|array $reparented = null;

    /** @var list<OrderedRelation> */
    private array $lockedOrderedRelations = [];

    public static function bootIsOrdered(): void
    {
        static::saving(static function (self $item): void {
            if (OrderWrite::replays($item)) {
                return;
            }

            $uncommitted = $item->reparented !== null;
            $item->restoredInto = null;
            $item->reparented = null;

            if (! $item->exists) {
                $item->lockedOrderedRelations = OrderedRelation::lockAll([$item->resolveOrderedRelation($item->getAttributes())]);
            } elseif ($item->isDirty(OrderedRelation::watchedColumns($item))) {
                [$current, $item->lockedOrderedRelations, $next, $membership] = $item->lockCurrentOrderedRelations(
                    static fn (null|array $stored): null|OrderedRelation => OrderedRelation::of(
                        $item,
                        $stored === null ? $item->getAttributes() : [...$stored, ...array_intersect_key($item->getDirty(), $stored)],
                        $item->heldOrderedParents(),
                    ),
                );

                if ($membership !== null) {
                    $clean = array_diff_key($membership, $item->getDirty());
                    $item->forceFill($clean)->syncOriginalAttributes(array_keys($clean));
                }

                $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($item), true);
                $kept = $uncommitted ? $item->getRawOriginal(OrderedRelation::RANK_KEY) : $item->getAttribute(OrderedRelation::RANK_KEY);

                if ($softDeletes && $item->getRawOriginal(OrderedRelations::DELETED_AT) !== null && $item->getAttribute(OrderedRelations::DELETED_AT) === null) {
                    $stored = $item->storedOrderedAttributes([OrderedRelation::RANK_KEY, OrderedRelations::DELETED_AT]);

                    if ($stored === null) {
                        return;
                    }

                    if ($stored[OrderedRelations::DELETED_AT] !== null) {
                        $item->setAttribute(OrderedRelation::RANK_KEY, $next?->keyAt(Placement::last()));
                        $item->restoredInto = $next;

                        return;
                    }

                    $kept = $stored[OrderedRelation::RANK_KEY];
                }

                if (! $softDeletes || $item->getAttribute(OrderedRelations::DELETED_AT) === null) {
                    $item->followOrderedParent($current, $next, $kept);
                }
            }
        });

        static::creating(static function (self $item): void {
            $item->forgetPosition();
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
            $written = OrderWrite::update($item, [$item->getKey()], [OrderedRelation::RANK_KEY => null], OrderedRelation::usesTimestamps($item));
            $item->forceFill($written)->syncOriginalAttributes(array_keys($written));
        });

        static::saved(static function (self $item): void {
            if (OrderWrite::replays($item)) {
                return;
            }

            $restored = $item->restoredInto;
            $reparented = $item->reparented;
            $item->restoredInto = null;
            $item->reparented = null;

            $item->refreshLockedOrderedRelations();
            $restored?->placed($item);

            if ($restored !== null || $reparented !== null) {
                $item->forgetPosition();
            }

            if ($reparented === null) {
                return;
            }

            [$source, $destination, $previous] = $reparented;

            if ($destination !== null) {
                $item->resolvedOrderedRelation = $destination;
            }

            if ($source !== null && $previous !== null) {
                if ($destination === null) {
                    $source->removed($item, $previous);
                } else {
                    $destination->entered($item, $source, $previous);
                }
            } elseif ($destination !== null) {
                $destination->placed($item);
            }
        });

        static::deleted(static function (self $item): void {
            $vacated = $item->vacatedPosition;
            $item->vacatedPosition = null;
            $item->forgetPosition();

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

        return OrderWrite::run($this, function () use ($placement, $expected): OrderChange {
            $destination = $placement->parent === null ? null : OrderedRelation::in($placement->parent, $this);
            $original = $this->getRawOriginal();
            [$source, , , $stored] = $this->lockCurrentOrderedRelations(static fn (): null|OrderedRelation => $destination);

            if ($source === null || $stored === null) {
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

            if ($change->source === null && array_diff_assoc($stored, $original) !== []) {
                $this->forceFill($stored)->syncOriginalAttributes(array_keys($stored));
            }

            $this->followMovedParent([$original, $stored], $change->relation->parent);
            $this->resolvedOrderedRelation = $change->relation;
            $this->forgetPosition();

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
        return Attribute::make(get: fn (mixed $value): null|int => match (true) {
            ! $this->exists => null,
            array_key_exists(OrderedRelation::POSITION, $this->attributes) => $value === null ? null : intval($value),
            default => $this->resolveOrderedRelation($this->getAttributes())?->position($this),
        });
    }

    private function forgetPosition(): void
    {
        unset($this->attributes[OrderedRelation::POSITION], $this->original[OrderedRelation::POSITION]);
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

    private function followOrderedParent(null|OrderedRelation $current, null|OrderedRelation $next, mixed $kept): void
    {
        if ($current === null ? $next === null : $next !== null && $current->is($next)) {
            $this->setAttribute(OrderedRelation::RANK_KEY, $kept);

            return;
        }

        $this->reparented = [$current, $next, $current?->position($this)];
        $this->setAttribute(OrderedRelation::RANK_KEY, $next?->keyAt(Placement::last()));
    }

    /**
     * @param  list<array<string, mixed>>  $left
     */
    private function followMovedParent(array $left, Model $parent): void
    {
        $follows = fn (mixed $model): bool => $model instanceof Model
            && ! $model->is($parent)
            && array_any($left, fn (array $attributes): bool => OrderedRelation::names($this, $attributes, $model));

        foreach ($this->getRelations() as $name => $related) {
            if (! $follows($related)) {
                continue;
            }

            if ($related::class === $parent::class) {
                $this->setRelation($name, $parent);
            } else {
                $this->unsetRelation($name);
            }
        }

        if ($this instanceof Pivot && $follows($this->pivotParent)) {
            $this->pivotParent = $parent;
        }
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
     * @param  null|(Closure(null|array<string, mixed>): (null|OrderedRelation))  $resolveNext
     * @return array{null|OrderedRelation, list<OrderedRelation>, null|OrderedRelation, null|array<string, mixed>}
     */
    private function lockCurrentOrderedRelations(null|Closure $resolveNext = null): array
    {
        $moved = new RuntimeException;

        for ($attempt = 1; $attempt <= self::ORDER_LOCK_ATTEMPTS; $attempt++) {
            $stored = $this->storedOrderedAttributes(OrderedRelation::membershipColumns($this));
            $current = $stored === null ? null : $this->resolveOrderedRelation($stored);
            $next = $resolveNext === null ? null : $resolveNext($stored);
            $locked = [];

            try {
                return $this->getConnection()->transaction(function () use ($stored, $current, $next, $moved, &$locked): array {
                    $locked = OrderedRelation::lockAll([$current, $next]);

                    throw_unless($this->storedOrderedAttributes(OrderedRelation::membershipColumns($this)) === $stored, $moved);

                    return [$current, $locked, $next, $stored];
                });
            } catch (RuntimeException $exception) {
                throw_unless($exception === $moved, $exception);
                OrderWrite::released($locked);
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
            return OrderWrite::run($this, function () use ($write, $halted, &$result): null|bool|int {
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
