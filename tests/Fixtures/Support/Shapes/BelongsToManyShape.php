<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Shapes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingCustomer;
use Tests\Fixtures\Support\Listings\ListingRevision;

final class BelongsToManyShape extends PivotShape
{
    public function createParent(): Listing
    {
        return Listing::create();
    }

    public function revision(): NamesConcurrencyRevision
    {
        return ListingRevision::CustomersOrder;
    }

    public function memberClass(): string
    {
        return ListingCustomer::class;
    }

    public function parentTable(): string
    {
        return 'listings';
    }

    public function memberTable(): string
    {
        return 'listing_customer';
    }

    public function foreignKey(): string
    {
        return 'listing_id';
    }

    public function parentRelation(): string
    {
        return 'listing';
    }

    public function relationClass(): string
    {
        return BelongsToMany::class;
    }

    public function associate(Model&Ordered $member, Model $parent): void
    {
        assert($member instanceof ListingCustomer && $parent instanceof Listing);

        $member->listing()->associate($parent);
    }

    public function customers(Model $parent): BelongsToMany
    {
        assert($parent instanceof Listing);

        return $parent->customers();
    }

    protected function membership(Model $parent): array
    {
        return ['listing_id' => $parent->getKey()];
    }
}
