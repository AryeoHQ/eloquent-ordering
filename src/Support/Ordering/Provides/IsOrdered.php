<?php

declare(strict_types=1);

namespace Support\Ordering\Provides;

use Illuminate\Database\Eloquent\Casts\Attribute;
use LogicException;
use Support\Ordering\Contracts\OrdersCollection;
use Support\Ordering\OrderedCollection;
use Support\Ordering\Placement;

trait IsOrdered
{
    private Placement|null $placement = null;

    public static function bootIsOrdered(): void
    {
        static::creating(function (self $item): void {
            $item->orderedIn()->place($item, $item->placement ?? Placement::last());
            $item->placement = null;
        });

        static::deleting(function (self $item): void {
            $item->orderedIn()->remove();
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

        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    public function delete(): bool|null
    {
        return $this->getConnection()->transaction(fn (): bool|null => parent::delete());
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
}
