<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Sections;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Concurrency\Provides\HasConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;
use Tests\Fixtures\Support\Assets\TouchingAsset;

/**
 * @property int $id
 * @property string|null $name
 * @property string $concurrency_revision
 */
class TouchedSection extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $table = 'sections';

    protected $guarded = [];

    /**
     * @return HasMany<TouchingAsset, $this>
     */
    #[OrdersRelation(SectionRevision::AssetsOrder)]
    public function assets(): HasMany
    {
        return $this->hasMany(TouchingAsset::class, 'section_id');
    }
}
