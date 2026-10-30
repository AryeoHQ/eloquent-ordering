<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Attributes;

use Attribute;
use Support\Database\Eloquent\Concurrency\Attributes\NamedConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class OrdersRelation extends NamedConcurrencyRevision
{
    /**
     * @param  list<string>  $membership
     */
    public function __construct(NamesConcurrencyRevision $revision, public readonly string $relation, public readonly array $membership = [])
    {
        parent::__construct($revision);
    }

    /**
     * @return list<string>
     */
    public function membershipOf(string $table): array
    {
        $columns = [];

        foreach ($this->membership as $column) {
            [$qualifier, $name] = array_pad(explode('.', $column, 2), -2, null);

            if ($qualifier === $table && $name !== null) {
                $columns[] = $name;
            }
        }

        return $columns;
    }
}
