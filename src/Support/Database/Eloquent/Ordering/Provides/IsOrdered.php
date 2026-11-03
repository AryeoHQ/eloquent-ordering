<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use RuntimeException;
use Support\Database\Eloquent\Concurrency\Concerns\WritesInTransactions;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Exceptions\NotAMember;
use Support\Database\Eloquent\Ordering\Exceptions\UnselectedPosition;
use Support\Database\Eloquent\Ordering\OrderChange;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\OrderWrite;
use Support\Database\Eloquent\Ordering\Placement;
use Support\Database\Eloquent\Ordering\Providers\Provider;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;
use Support\Database\Eloquent\Ordering\Scopes\SelectsPosition;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Ordered
 */
trait IsOrdered
{
    use LocksOrderedParentsFirst;
    use WritesInTransactions;

    private null|OrderedRelation $resolvedOrderedRelation = null;

    private null|Placement $placement = null;

    private null|int $vacatedPosition = null;

    private null|OrderedRelation $restoredInto = null;

    /** @var null|array{null|OrderedRelation, null|OrderedRelation, null|int} */
    private null|array $reparented = null;

    /** @var list<OrderedRelation> */
    private array $lockedOrderedRelations = [];

    /** @var null|array{OrderedRelation, null|int} */
    private null|array $refilteredPosition = null;

    /** @var null|array{mixed} */
    private null|array $assignedRankKey = null;

    public static function bootIsOrdered(): void
    {
        static::addGlobalScope(new SelectsPosition);

        static::saving(static function (self $item): void {
            if (OrderWrite::saves($item)) {
                return;
            }

            self::prepareOrderedSave($item);
            OrderedRelation::prepared($item);
        });

        static::creating(static function (self $item): void {
            OrderedRelation::requirePrepared($item);
            $item->forgetPosition();

            $relation = $item->currentOrderedRelation();
            $relation = array_find($item->lockedOrderedRelations, static fn (OrderedRelation $locked): bool => $locked->is($relation)) ?? $relation;

            $item->assignRankKey($relation->keyForCreate($item, $item->placement ?? Placement::last()));
            OrderedRelation::prepared($item);
        });

        static::replicating(static function (self $item): void {
            unset($item->attributes[OrderedRelation::RANK_KEY]);
        });

        static::created(static function (self $item): void {
            $item->placement = null;
            $relations = $item->lockedOrderedRelations;
            $item->lockedOrderedRelations = [];

            foreach ($relations as $relation) {
                $relation->placed($item);
                $relation->announceRebalance();
            }

            $item->selectStoredPosition();
        });

        static::deleting(static function (self $item): void {
            if (Provider::locksPivotParentsOf($item, deleting: true)) {
                OrderedRelation::lockParentsFirst($item);
            }

            [$current, $item->lockedOrderedRelations] = $item->lockCurrentOrderedRelations();
            $item->lockRelatedOfOrderedRelations();
            $item->vacatedPosition = $current?->memberPosition($item);
        });

        static::registerModelEvent('trashed', static function (self $item): void {
            $copy = OrderWrite::copy($item, OrderedRelation::usesTimestamps($item));

            if ($copy !== null) {
                $written = OrderWrite::save($copy, [OrderedRelation::RANK_KEY => null]);
                $item->forceFill($written)->syncOriginalAttributes(array_keys($written));
            }
        });

        static::saved(static function (self $item): void {
            OrderedRelation::forgetParentsLockedFirst($item);

            if (OrderWrite::saves($item)) {
                return;
            }

            $restored = $item->restoredInto;
            $reparented = $item->reparented;
            $refiltered = $item->refilteredPosition;
            $relations = $item->lockedOrderedRelations;
            $item->restoredInto = null;
            $item->reparented = null;
            $item->refilteredPosition = null;
            $item->lockedOrderedRelations = [];
            $item->assignedRankKey = null;

            if ($refiltered !== null) {
                $refiltered[0]->refiltered($item, $refiltered[1]);
            }

            $restored?->placed($item);

            if ($reparented !== null) {
                $item->announceReparent(...$reparented);
            }

            foreach ($relations as $relation) {
                $relation->announceRebalance();
            }

            if ($restored !== null || $reparented !== null || $refiltered !== null || $item->wasChanged(OrderedRelations::DELETED_AT)) {
                $item->selectStoredPosition();
            }
        });

        static::deleted(static function (self $item): void {
            OrderedRelation::forgetParentsLockedFirst($item);
            $vacated = $item->vacatedPosition;
            $relations = $item->lockedOrderedRelations;
            $item->vacatedPosition = null;
            $item->lockedOrderedRelations = [];
            $item->forceFill([OrderedRelation::POSITION => null])->syncOriginalAttributes([OrderedRelation::POSITION]);

            foreach ($relations as $relation) {
                if ($vacated !== null) {
                    $relation->removed($item, $vacated);
                }
            }
        });
    }

    public function initializeIsOrdered(): void
    {
        $this->makeHidden(OrderedRelation::RANK_KEY);

        self::leaveRankKeyUnobserved($this);
    }

    public function placeAt(Placement $placement): static
    {
        throw_if($this->exists, LogicException::class, sprintf('[%s:%s] already has a place in its order.', static::class, $this->getKey()));
        throw_if($placement->parent !== null, LogicException::class, sprintf('Associate the parent of a new [%s] rather than name it in the placement.', static::class));

        $this->placement = $placement;

        return $this;
    }

    public function move(Placement $placement, null|string $expected): OrderChange
    {
        throw_unless($this->exists, LogicException::class, sprintf('[%s] must be saved before it moves.', static::class));

        return $this->getConnection()->transaction(function () use ($placement, $expected): OrderChange {
            $destination = $placement->parent === null ? null : OrderedRelation::in($placement->parent, $this);
            $original = $this->getRawOriginal();
            [$source, , , $stored] = $this->lockCurrentOrderedRelations(static fn (): null|OrderedRelation => $destination);

            if ($expected !== null) {
                $read = $this->remember(OrderedRelation::sourceOf($this, $this->getRawOriginal(), $this->heldOrderedParents()));

                if ($source !== null && ($read === null || ! $read->is($source))) {
                    throw new StaleConcurrencyRevision(($read ?? $source)->parent, ($read ?? $source)->revision, $expected);
                }

                ($destination ?? $source ?? $read)?->compareConcurrencyRevision($expected);
            }

            if ($source === null || $stored === null) {
                throw new NotAMember(static::class, [OrderedRelation::keyOf($this)]);
            }

            $source->requireMember($this);

            $target = $destination ?? $source;
            $change = $target->move($this, $placement, $source);

            if ($change->source === null && array_diff_assoc($stored, $original) !== []) {
                $this->forceFill($stored)->syncOriginalAttributes(array_keys($stored));
            }

            OrderedRelation::followParent($this, [$original, $stored], $target->parent);
            $this->resolvedOrderedRelation = $target;
            $this->selectStoredPosition();

            return $change;
        });
    }

    /**
     * @return Builder<static>
     */
    public function newQueryWithoutScopes()
    {
        return $this->selectPositionOnKeyQuery(parent::newQueryWithoutScopes()); // @phpstan-ignore return.type
    }

    /**
     * @template TQuery of Builder<*>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    protected function selectPositionOnKeyQuery(Builder $query): Builder
    {
        return $query->withGlobalScope(SelectsPosition::class, new SelectsPosition);
    }

    /**
     * @return Attribute<null|int, never>
     */
    protected function position(): Attribute
    {
        return Attribute::make(get: fn (mixed $value): null|int => match (true) {
            ! $this->exists => null,
            array_key_exists(OrderedRelation::POSITION, $this->attributes) => $value === null ? null : intval($value),
            default => throw new UnselectedPosition($this),
        });
    }

    private static function leaveRankKeyUnobserved(Model $item): void
    {
        if (method_exists($item, 'mergeUnobservedColumns')) {
            $item->mergeUnobservedColumns([OrderedRelation::RANK_KEY]);
        }
    }

    private static function prepareOrderedSave(self $item): void
    {
        $assigned = $item->assignedRankKey;
        $item->assignedRankKey = null;
        $rankKey = $item->getAttributes()[OrderedRelation::RANK_KEY] ?? null;

        throw_if(
            ($item->exists ? $item->isDirty(OrderedRelation::RANK_KEY) : $rankKey !== null) && $assigned !== [$rankKey],
            LogicException::class,
            sprintf('[%s:%s] sets its [%s], which only an order write may set. Move it with move(), or place a new member with placeAt().', $item::class, $item->getKey(), OrderedRelation::RANK_KEY),
        );

        $reassigned = $item->exists ? array_values(array_filter(OrderedRelation::relatedKeyColumns($item), $item->isDirty(...))) : [];

        throw_if($reassigned !== [], LogicException::class, sprintf(
            '[%s:%s] changes its [%s]. Detach it with detach(), then attach the new related model with attach().',
            $item::class,
            $item->getKey(),
            implode('], [', $reassigned),
        ));

        $uncommitted = $item->reparented !== null;
        $item->restoredInto = null;
        $item->reparented = null;
        $item->refilteredPosition = null;
        $item->lockedOrderedRelations = [];

        if ($item->exists && Provider::locksPivotParentsOf($item, deleting: false)) {
            OrderedRelation::lockParentsFirst($item);
        }

        if (! $item->exists) {
            $item->lockDestinationOfCreate();
        } elseif ($item->isDirty(OrderedRelation::watchedColumns($item))) {
            [$current, $item->lockedOrderedRelations, $next, $membership] = $item->lockCurrentOrderedRelations(
                static fn (null|array $stored): null|OrderedRelation => OrderedRelation::destinationOf(
                    $item,
                    $stored === null ? $item->getAttributes() : [...$stored, ...array_intersect_key($item->getDirty(), $stored)],
                    $item->heldOrderedParents(),
                ),
            );
            $item->lockRelatedOfOrderedRelations();

            $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($item), true);
            $declared = OrderedRelation::declaredColumns($item);
            $stored = $item->storedOrderedAttributes([
                OrderedRelation::RANK_KEY,
                ...OrderedRelation::membershipColumns($item),
                ...$declared,
                ...$softDeletes ? [OrderedRelations::DELETED_AT] : [],
            ]);

            if ($membership !== null) {
                $clean = array_diff_key($membership, $item->getDirty());
                $item->forceFill($clean)->syncOriginalAttributes(array_keys($clean));
            }

            $kept = $uncommitted ? $item->getRawOriginal(OrderedRelation::RANK_KEY) : $item->getAttribute(OrderedRelation::RANK_KEY);

            if ($softDeletes && $item->getRawOriginal(OrderedRelations::DELETED_AT) !== null && $item->getAttribute(OrderedRelations::DELETED_AT) === null) {
                if ($stored === null) {
                    return;
                }

                if ($stored[OrderedRelations::DELETED_AT] !== null) {
                    $next?->refuseUnavailable();
                    $item->assignRankKey($next?->keyAt(Placement::last()));
                    $item->restoredInto = $next;

                    return;
                }

                $kept = $stored[OrderedRelation::RANK_KEY];
            }

            if (! $softDeletes || $item->getAttribute(OrderedRelations::DELETED_AT) === null) {
                $item->followOrderedParent($current, $next, $kept);
            }

            if ($item->reparented === null && $current !== null && $declared !== [] && $item->isDirty($declared)) {
                $item->refilteredPosition = [$current, $current->memberPosition($item)];
            }
        }
    }

    /**
     * @return list<Model>
     */
    protected function orderingParentsToLockFirst(): array
    {
        return [
            ...OrderedRelation::namedParents($this, $this->getRawOriginal(), $this->heldOrderedParents()),
            ...OrderedRelation::namedParents($this, $this->getAttributes(), $this->heldOrderedParents()),
        ];
    }

    private function lockRelatedOfOrderedRelations(): void
    {
        foreach ($this->lockedOrderedRelations as $relation) {
            $relation->lockRelatedOf($this);
        }
    }

    private function forgetPosition(): void
    {
        unset($this->attributes[OrderedRelation::POSITION], $this->original[OrderedRelation::POSITION]);
    }

    private function announceReparent(null|OrderedRelation $source, null|OrderedRelation $destination, null|int $previous): void
    {
        if ($destination !== null) {
            $this->resolvedOrderedRelation = $destination;
        }

        if ($source !== null && $previous !== null) {
            if ($destination === null) {
                $source->removed($this, $previous);
            } else {
                $destination->entered($this, $source, $previous);
            }
        } elseif ($destination !== null) {
            $destination->placed($this);
        }
    }

    private function selectStoredPosition(): void
    {
        $key = $this->getKey();
        $row = $this->exists && (is_int($key) || is_string($key))
            ? $this->selectPositionOnKeyQuery($this->newModelQuery())->whereKey($key)->useWritePdo()->toBase()->first()
            : null;
        $stored = is_object($row) ? (array) $row : [OrderedRelation::POSITION => null];

        if (! array_key_exists(OrderedRelation::POSITION, $stored)) {
            $this->forgetPosition();

            return;
        }

        $this->forceFill([OrderedRelation::POSITION => $stored[OrderedRelation::POSITION]])->syncOriginalAttributes([OrderedRelation::POSITION]);
    }

    private function currentOrderedRelation(): OrderedRelation
    {
        return $this->resolveOrderedRelation($this->getAttributes())
            ?? throw new LogicException(sprintf('[%s:%s] has no parent that orders it.', static::class, $this->getKey()));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveOrderedRelation(array $attributes): null|OrderedRelation
    {
        return $this->remember(OrderedRelation::of($this, $attributes, $this->heldOrderedParents()));
    }

    private function lockDestinationOfCreate(): void
    {
        $this->lockedOrderedRelations = OrderedRelation::lockAll([$this->remember(OrderedRelation::destinationOf($this, $this->getAttributes(), $this->heldOrderedParents()))]);

        foreach ($this->lockedOrderedRelations as $relation) {
            $relation->refuseUnavailable();
        }

        $this->lockRelatedOfOrderedRelations();
    }

    private function remember(null|OrderedRelation $relation): null|OrderedRelation
    {
        if ($relation !== null) {
            $this->resolvedOrderedRelation = $relation;
        }

        return $relation;
    }

    private function followOrderedParent(null|OrderedRelation $current, null|OrderedRelation $next, mixed $kept): void
    {
        if ($current === null ? $next === null : $next !== null && $current->is($next)) {
            $this->assignRankKey($kept);

            return;
        }

        $next?->refuseUnavailable();
        $this->reparented = [$current, $next, $current?->memberPosition($this)];
        $this->assignRankKey($next?->keyAt(Placement::last()));
    }

    private function assignRankKey(mixed $key): void
    {
        $this->setAttribute(OrderedRelation::RANK_KEY, $key);
        $this->assignedRankKey = [$key];
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
        return OrderedRelation::retryWhileMoved($this, function (RuntimeException $moved) use ($resolveNext): array {
            $stored = $this->storedOrderedAttributes(OrderedRelation::membershipColumns($this));
            $current = $stored === null ? null : $this->remember(OrderedRelation::sourceOf($this, $stored, $this->heldOrderedParents()));
            $next = $resolveNext === null ? null : $resolveNext($stored);

            return $this->getConnection()->transaction(function () use ($stored, $current, $next, $moved): array {
                $locked = OrderedRelation::lockAll([$current, $next]);

                throw_unless($this->storedOrderedAttributes(OrderedRelation::membershipColumns($this)) === $stored, $moved);

                return [$current, $locked, $next, $stored];
            });
        });
    }

    /**
     * @param  list<string>  $columns
     * @return null|array<string, mixed>
     */
    private function storedOrderedAttributes(array $columns): null|array
    {
        $stored = $this->newQueryWithoutScopes()->withoutGlobalScope(SelectsPosition::class)->whereKey($this->getKey())->toBase()->useWritePdo()->first($columns);

        return $stored === null ? null : (array) $stored;
    }
}
