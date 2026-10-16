<?php

declare(strict_types=1);

namespace Support\Ordering;

final readonly class Placement
{
    private function __construct(
        public bool $first = false,
        public int|string|null $before = null,
        public int|string|null $after = null,
    ) {}

    public static function first(): self
    {
        return new self(first: true);
    }

    public static function last(): self
    {
        return new self;
    }

    public static function before(int|string $anchor): self
    {
        return new self(before: $anchor);
    }

    public static function after(int|string $anchor): self
    {
        return new self(after: $anchor);
    }
}
