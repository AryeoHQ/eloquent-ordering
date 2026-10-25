<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Closure;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RuntimeException;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Placement;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Ordered
 */
trait IsOrdered
{
    use ResolvesOrderedRelations;

    private null|Placement $placement = null;

    /** @var list<OrderedRelation> */
    private array $lockedOrderedRelations = [];

    public static function bootIsOrdered(): void
    {
        static::saving(static function (self $item): void {
            if (! $item->exists) {
                $item->lockOrderedRelations([$item->getAttributes()]);
            } elseif ($item->isDirty(OrderedRelation::watchedColumns($item))) {
                $item->lockOrderedRelations([$item->getRawOriginal(), $item->getAttributes()]);
            }
        });

        static::creating(static function (self $item): void {
            $item->setAttribute(OrderedRelation::RANK_KEY, $item->orderedRelation()->keyAt($item->placement ?? Placement::last()));
            $item->placement = null;
        });

        static::deleting(static function (self $item): void {
            $item->lockOrderedRelations([$item->getRawOriginal()]);
        });

        static::registerModelEvent('trashed', static function (self $item): void {
            $item->newQueryWithoutScopes()->whereKey($item->getKey())->toBase()->update([OrderedRelation::RANK_KEY => null]);
            $item->setAttribute(OrderedRelation::RANK_KEY, null)->syncOriginalAttribute(OrderedRelation::RANK_KEY);
        });

        static::saved(static function (self $item): void {
            $item->refreshLockedOrderedRelations();
        });

        static::deleted(static function (self $item): void {
            $item->refreshLockedOrderedRelations();
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

    public function delete(): null|bool
    {
        return $this->commitUnlessHalted(fn (): null|bool => parent::delete());
    }

    public function placeAt(Placement $placement): static
    {
        throw_if($this->exists, LogicException::class, sprintf('[%s:%s] already has a place in its order.', static::class, $this->getKey()));

        $this->placement = $placement;

        return $this;
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
     * @param  list<array<string, mixed>>  $states
     */
    private function lockOrderedRelations(array $states): void
    {
        $relations = [];

        foreach ($states as $attributes) {
            $relation = $this->resolveOrderedRelation($attributes);

            if ($relation !== null) {
                $relations[sprintf('%s:%s', $relation->parent->getMorphClass(), $relation->parent->getKey())] = $relation;
            }
        }

        uasort($relations, static fn (OrderedRelation $first, OrderedRelation $second): int => [$first->parent->getMorphClass(), $first->parent->getKey()]
            <=> [$second->parent->getMorphClass(), $second->parent->getKey()]);

        foreach ($relations as $relation) {
            $relation->lock();
        }

        $this->lockedOrderedRelations = array_values($relations);
    }

    private function refreshLockedOrderedRelations(): void
    {
        foreach ($this->lockedOrderedRelations as $relation) {
            $relation->refreshConcurrencyRevision();
        }

        $this->lockedOrderedRelations = [];
    }

    /**
     * @template TResult of null|bool
     *
     * @param  Closure(): TResult  $write
     * @return TResult|false
     */
    private function commitUnlessHalted(Closure $write): null|bool
    {
        $halted = new RuntimeException;

        try {
            return $this->getConnection()->transaction(function () use ($write, $halted): null|bool {
                $result = $write();

                throw_if($result === false, $halted);

                return $result;
            });
        } catch (RuntimeException $exception) {
            throw_unless($exception === $halted, $exception);

            return false;
        }
    }
}
