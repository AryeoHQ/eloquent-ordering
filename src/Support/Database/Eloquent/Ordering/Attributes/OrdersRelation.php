<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Attributes;

use Attribute;
use Support\Database\Eloquent\Concurrency\Attributes\NamedConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class OrdersRelation extends NamedConcurrencyRevision
{
    public function __construct(NamesConcurrencyRevision $revision, public readonly string $relation)
    {
        parent::__construct($revision);
    }
}
