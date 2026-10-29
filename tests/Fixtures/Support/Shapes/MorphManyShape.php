<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Shapes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Tests\Fixtures\Support\Scenes\Scene;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Tours\Tour;
use Tests\Fixtures\Support\Tours\TourRevision;

final class MorphManyShape extends Shape
{
    public function createParent(): Tour
    {
        return Tour::create();
    }

    public function revision(): NamesConcurrencyRevision
    {
        return TourRevision::ScenesOrder;
    }

    public function memberClass(): string
    {
        return Scene::class;
    }

    public function parentTable(): string
    {
        return 'tours';
    }

    public function memberTable(): string
    {
        return 'scenes';
    }

    public function foreignKey(): string
    {
        return 'sceneable_id';
    }

    public function parentRelation(): string
    {
        return 'sceneable';
    }

    public function relationClass(): string
    {
        return MorphMany::class;
    }

    public function associate(Model&Ordered $member, Model $parent): void
    {
        assert($member instanceof Scene);

        $member->sceneable()->associate($parent);
    }

    public function stranger(): Model
    {
        return Section::create();
    }

    protected function make(bool $hidden): Scene
    {
        return new Scene(['visible' => ! $hidden]);
    }

    public function item(Model $result): Scene
    {
        assert($result instanceof Scene);

        return $result;
    }

    protected function memberKey(Model $result): string
    {
        assert($result instanceof Scene);

        return $result->id;
    }

    protected function membership(Model $parent): array
    {
        return ['sceneable_type' => $parent->getMorphClass(), 'sceneable_id' => $parent->getKey()];
    }
}
