<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
final readonly class RelationKeys
{
    public function __construct(
        public Model $item,
        public string $foreignKey,
        public string $ownerKey,
        public null|string $morphType = null,
        public null|string $morphClass = null,
        public null|string $relatedKey = null,
    ) {}

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->morphType === null ? [$this->foreignKey] : [$this->foreignKey, $this->morphType];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function names(array $attributes): bool
    {
        return ($attributes[$this->foreignKey] ?? null) !== null
            && ($this->morphType === null || ($attributes[$this->morphType] ?? null) === $this->morphClass);
    }

    /**
     * @return array<string, mixed>
     */
    public function pointingAt(Model $parent): array
    {
        return [
            $this->foreignKey => $parent->getAttribute($this->ownerKey),
            ...($this->morphType === null ? [] : [$this->morphType => $this->morphClass]),
        ];
    }

    /**
     * @return array<string, null>
     */
    public function detached(): array
    {
        return array_fill_keys($this->columns(), null);
    }
}
