<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Shapes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Tests\Fixtures\Support\Assets\Asset;
use Tests\Fixtures\Support\Sections\Section;
use Tests\Fixtures\Support\Sections\SectionRevision;
use Tests\Fixtures\Support\Videos\Video;

final class HasManyShape extends Shape
{
    public function createParent(): Section
    {
        return Section::create();
    }

    public function revision(): NamesConcurrencyRevision
    {
        return SectionRevision::AssetsOrder;
    }

    public function memberClass(): string
    {
        return Asset::class;
    }

    public function parentTable(): string
    {
        return 'sections';
    }

    public function memberTable(): string
    {
        return 'assets';
    }

    public function foreignKey(): string
    {
        return 'section_id';
    }

    public function parentRelation(): string
    {
        return 'section';
    }

    public function relationClass(): string
    {
        return HasMany::class;
    }

    public function associate(Model&Ordered $member, Model $parent): void
    {
        assert($member instanceof Asset && $parent instanceof Section);

        $member->section()->associate($parent);
    }

    public function stranger(): Model
    {
        $video = new Video;
        $video->section()->associate(Section::create());
        $video->save();

        return $video;
    }

    protected function make(bool $hidden): Asset
    {
        return new Asset(['visible' => ! $hidden]);
    }

    public function item(Model $result): Asset
    {
        assert($result instanceof Asset);

        return $result;
    }

    protected function memberKey(Model $result): int
    {
        assert($result instanceof Asset);

        return $result->id;
    }

    protected function membership(Model $parent): array
    {
        return ['section_id' => $parent->getKey()];
    }
}
