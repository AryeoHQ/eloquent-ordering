<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Testing;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Placement;

/**
 * @phpstan-require-extends Factory
 */
trait PlacesOrderedItems
{
    public function placedAt(Placement $placement): static
    {
        return $this->afterMaking(static fn (Model&Ordered $item): Model => $item->placeAt($placement));
    }
}
