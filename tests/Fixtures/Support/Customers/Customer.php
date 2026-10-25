<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Customers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingCustomer;
use Tests\Fixtures\Support\Tours\Tour;

/**
 * @property int $id
 * @property string|null $name
 * @property bool $visible
 */
class Customer extends Model
{
    public const string VISIBLE_SCOPE = 'visible';

    protected $guarded = [];

    /**
     * @return BelongsToMany<Listing, $this, ListingCustomer>
     */
    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'listing_customer')
            ->using(ListingCustomer::class)
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    /**
     * @return MorphToMany<Tour, $this, Customerable>
     */
    public function tours(): MorphToMany
    {
        return $this->morphedByMany(Tour::class, 'customerable')
            ->using(Customerable::class)
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(self::VISIBLE_SCOPE, static function (Builder $query): void {
            $query->where($query->qualifyColumn('visible'), true);
        });
    }
}
