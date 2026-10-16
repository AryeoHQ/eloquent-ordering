<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Tours;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Ordering\Contracts\OrdersCollection;
use Support\Ordering\Provides\HasOrderedCollections;
use Tests\Fixtures\Support\Scenes\Scene;

/**
 * @property string $id
 * @property int $scenes_order_version
 */
#[UseFactory(TourFactory::class)]
class Tour extends Model implements OrdersCollection
{
    /** @use HasFactory<TourFactory> */
    use HasFactory;

    use HasOrderedCollections;
    use HasUuids;

    protected $guarded = [];

    /**
     * @return HasMany<Scene, $this>
     */
    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class);
    }
}
