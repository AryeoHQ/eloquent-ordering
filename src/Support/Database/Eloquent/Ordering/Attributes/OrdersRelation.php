<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Ordering\Attributes;

use Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Support\Database\Eloquent\Concurrency\Attributes\NamedConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Ordering\OrderedRelation;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class OrdersRelation extends NamedConcurrencyRevision
{
    /**
     * @param  list<string>  $membership
     */
    public function __construct(NamesConcurrencyRevision $revision, public readonly array $membership = [])
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

    /**
     * @param  Relation<*, *, *>  $relation
     * @return Builder<*>
     */
    public function digestQuery(Relation $relation): Builder
    {
        return OrderedRelation::memberQuery($relation->getParent(), $this->revision);
    }
}
