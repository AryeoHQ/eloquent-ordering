<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Provides;

use Closure;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RuntimeException;
use Support\Database\Eloquent\Concurrency\Exceptions\StaleConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\OrderChange;
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
                $item->lockedOrderedRelations = OrderedRelation::lockAll([$item->resolveOrderedRelation($item->getAttributes())]);
            } elseif ($item->isDirty(OrderedRelation::watchedColumns($item))) {
                [, $item->lockedOrderedRelations] = $item->lockCurrentOrderedRelations($item->resolveOrderedRelation($item->getAttributes()));
            }
        });

        static::creating(static function (self $item): void {
            $item->setAttribute(OrderedRelation::RANK_KEY, $item->orderedRelation()->keyAt($item->placement ?? Placement::last()));
            $item->placement = null;
        });

        static::deleting(static function (self $item): void {
            [, $item->lockedOrderedRelations] = $item->lockCurrentOrderedRelations();
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

                $source->compareConcurrencyRevision($expected);
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
            }

            $this->resolvedOrderedRelation = $change->relation;

            return $change;
        });
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
     * @return array{null|OrderedRelation, list<OrderedRelation>}
     */
    private function lockCurrentOrderedRelations(null|OrderedRelation $next = null): array
    {
        $stored = $this->storedOrderedAttributes();
        $current = $stored === null ? null : $this->resolveOrderedRelation($stored);
        $moved = new RuntimeException;

        try {
            return $this->getConnection()->transaction(function () use ($stored, $current, $next, $moved): array {
                $locked = OrderedRelation::lockAll([$current, $next]);

                throw_unless($this->storedOrderedAttributes() === $stored, $moved);

                return [$current, $locked];
            });
        } catch (RuntimeException $exception) {
            throw_unless($exception === $moved, $exception);

            return $this->lockCurrentOrderedRelations($next);
        }
    }

    /**
     * @return null|array<string, mixed>
     */
    private function storedOrderedAttributes(): null|array
    {
        $stored = $this->newQueryWithoutScopes()->whereKey($this->getKey())->toBase()->useWritePdo()->first(OrderedRelation::foreignKeys($this));

        return $stored === null ? null : (array) $stored;
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
