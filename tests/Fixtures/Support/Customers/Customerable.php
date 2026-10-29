<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Customers;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;
use Tests\Fixtures\Support\Events\ModelUpdated;
use Tests\Fixtures\Support\Tours\Tour;

/**
 * @property string $id
 * @property int $customer_id
 * @property int $customerable_id
 * @property string $customerable_type
 * @property string|null $rank_key
 * @property string|null $title
 * @property-read int|null $position
 */
#[OrderedIn(Tour::class)]
class Customerable extends MorphPivot implements Ordered
{
    use HasUuids;
    use IsOrdered;
    use SoftDeletes;

    protected $table = 'customerables';

    protected $dispatchesEvents = ['updated' => ModelUpdated::class];

    /**
     * @return MorphTo<Model, $this>
     */
    public function customerable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
