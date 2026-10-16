<?php

declare(strict_types=1);

namespace Support\Ordering\Provides;

use Closure;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use RuntimeException;
use Support\Ordering\Contracts\OrdersCollection;
use Support\Ordering\OrderedCollection;
use Support\Ordering\Placement;

trait IsOrdered
{
    private Placement|null $placement = null;

    public static function bootIsOrdered(): void
    {
        throw_if(
            in_array(SoftDeletes::class, class_uses_recursive(static::class), true),
            LogicException::class,
            sprintf('[%s] cannot use soft deletes, because a trashed item keeps its place in the order.', static::class),
        );

        static::creating(function (self $item): void {
            OrderedCollection::place($item, $item->placement ?? Placement::last());
            $item->placement = null;
        });

        static::deleting(function (self $item): void {
            OrderedCollection::remove($item);
        });
    }

    public function initializeIsOrdered(): void
    {
        $this->makeHidden($this->getRankKeyName());
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            return parent::save($options);
        }

        // The creating hook locks the parent row; the transaction holds that lock through the insert.
        return $this->commitUnlessHalted(fn (): bool => parent::save($options));
    }

    public function delete(): bool|null
    {
        return $this->commitUnlessHalted(fn (): bool|null => parent::delete());
    }

    public function getRankKeyName(): string
    {
        return 'rank_key';
    }

    public function placeAt(Placement $placement): static
    {
        throw_if($this->exists, LogicException::class, sprintf('Move [%s:%s] through its OrderedCollection.', static::class, $this->getKey()));

        $this->placement = $placement;

        return $this;
    }

    public function orderedIn(): OrderedCollection
    {
        $parent = $this->getRelationValue($this->orderedParent()->getRelationName());

        throw_unless($parent instanceof OrdersCollection, LogicException::class, sprintf('[%s:%s] has no parent that orders it.', static::class, $this->getKey()));

        return $parent->orderedCollection($this->orderedCollectionName());
    }

    /**
     * @return Attribute<int|null, never>
     */
    protected function position(): Attribute
    {
        return Attribute::make(get: function (): int|null {
            if (! $this->exists) {
                return null;
            }

            $self = $this->newQueryWithoutScopes()->whereKey($this->getKey())->toBase();
            $foreignKey = $this->orderedParent()->getForeignKeyName();
            $rankKey = $this->getRankKeyName();

            return $this->newQueryWithoutScopes()->toBase()
                ->where($foreignKey, '=', (clone $self)->select($foreignKey))
                ->where($rankKey, '<', (clone $self)->select($rankKey))
                ->count() + 1;
        });
    }

    /**
     * @template TResult of bool|null
     *
     * @param  Closure(): TResult  $write
     * @return TResult|false
     */
    private function commitUnlessHalted(Closure $write): bool|null
    {
        $halted = new RuntimeException;

        try {
            return $this->getConnection()->transaction(function () use ($write, $halted): bool|null {
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
