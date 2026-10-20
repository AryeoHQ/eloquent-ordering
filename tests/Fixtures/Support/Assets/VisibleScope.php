<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Assets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Asset>
 */
class VisibleScope implements Scope
{
    /**
     * @param  Builder<covariant Asset>  $builder
     * @param  Asset  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('visible'), true);
    }
}
