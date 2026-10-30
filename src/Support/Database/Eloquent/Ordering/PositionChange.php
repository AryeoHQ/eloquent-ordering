<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering;

final readonly class PositionChange
{
    public function __construct(
        public int|string $id,
        public null|int $position,
        public null|int $previousPosition,
    ) {}
}
