<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Shapes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Tests\Fixtures\Support\Customers\Customer;
use Tests\Fixtures\Support\Sections\Section;

abstract class PivotShape extends Shape
{
    /**
     * @return iterable<string, array{PivotShape}>
     */
    public static function pivots(): iterable
    {
        yield 'belongs to many' => [new BelongsToManyShape];
        yield 'morph to many' => [new MorphToManyShape];
    }

    /**
     * @return BelongsToMany<Customer, covariant Model, covariant Pivot>
     */
    abstract public function customers(Model $parent): BelongsToMany;

    public function stranger(): Model
    {
        return Section::create();
    }

    public function pivotOf(Model $parent, Customer $customer): Model&Ordered
    {
        $pivot = $this->memberClass()::query()
            ->where($this->membership($parent))
            ->where('customer_id', $customer->id)
            ->sole();

        assert($pivot instanceof Ordered);

        return $pivot;
    }

    protected function make(bool $hidden): Model&Ordered
    {
        $class = $this->memberClass();

        return new $class(['customer_id' => Customer::create(['visible' => ! $hidden])->id]);
    }

    protected function memberKey(Model $result): int|string
    {
        $pivot = $result->getRelation('pivot');

        assert($pivot instanceof Pivot);

        $key = $pivot->getKey();

        assert(is_string($key));

        return $key;
    }
}
