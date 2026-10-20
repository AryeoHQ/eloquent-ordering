<?php

declare(strict_types=1);

namespace Support\Ordering\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Support\Ordering\OrderedCollection;
use Support\Ordering\Placement;

interface Ordered
{
    /**
     * @return BelongsTo<*, *>
     */
    public function orderedParent(): BelongsTo;

    public function orderedCollectionName(): string;

    public function orderedIn(): OrderedCollection;

    public function getRankKeyName(): string;

    public function placeAt(Placement $placement): static;
}
