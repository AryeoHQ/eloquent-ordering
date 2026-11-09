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
use Tests\Fixtures\Support\Assets\UppercasedAsset;

/**
 * @property int $id
 * @property string|null $name
 * @property string $concurrency_revision
 */
class UppercasedSection extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    protected $table = 'sections';

    protected $guarded = [];

    /**
     * @return HasMany<UppercasedAsset, $this>
     */
    #[OrdersRelation(SectionRevision::AssetsOrder, membership: ['assets.title', 'sections.name'])]
    public function assets(): HasMany
    {
        return $this->hasMany(UppercasedAsset::class, 'section_id')->where('assets.title', strtoupper($this->getAttribute('name')));
    }
}
