<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Sections;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Ordering\Contracts\OrdersCollection;
use Support\Ordering\Provides\HasOrderedCollections;
use Tests\Fixtures\Support\Assets\Asset;

/**
 * @property int $id
 * @property string|null $name
 * @property int $assets_order_version
 */
#[UseFactory(SectionFactory::class)]
class Section extends Model implements OrdersCollection
{
    /** @use HasFactory<SectionFactory> */
    use HasFactory;

    use HasOrderedCollections;

    protected $guarded = [];

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
