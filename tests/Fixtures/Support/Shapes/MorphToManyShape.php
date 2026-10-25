<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Shapes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Tests\Fixtures\Support\Customers\Customerable;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Tours\TourRevision;

final class MorphToManyShape extends PivotShape
{
    public function createParent(): Tour
    {
        return Tour::create();
    }

    public function revision(): NamesConcurrencyRevision
    {
        return TourRevision::CustomersOrder;
    }

    public function memberClass(): string
    {
        return Customerable::class;
    }

    public function parentTable(): string
    {
        return 'tours';
    }

    public function memberTable(): string
    {
        return 'customerables';
    }

    public function foreignKey(): string
    {
        return 'customerable_id';
    }

    public function parentRelation(): string
    {
        return 'customerable';
    }

    public function relationClass(): string
    {
        return MorphToMany::class;
    }

    public function associate(Model&Ordered $member, Model $parent): void
    {
        assert($member instanceof Customerable && $parent instanceof Tour);

        $member->customerable()->associate($parent);
    }

    public function customers(Model $parent): BelongsToMany
    {
        assert($parent instanceof Tour);

        return $parent->customers();
    }

    protected function membership(Model $parent): array
    {
        return ['customerable_type' => $parent->getMorphClass(), 'customerable_id' => $parent->getKey()];
    }
}
