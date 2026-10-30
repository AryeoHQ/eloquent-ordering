<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Shapes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Tests\Fixtures\Support\Galleries\Gallery;
use Tests\Fixtures\Support\Galleries\GalleryRevision;
use Tests\Fixtures\Support\Slides\Slide;

final class UuidMorphManyShape extends Shape
{
    public const string NAME = 'uuid morph many';

    /**
     * @return array<string, class-string<Model>>
     */
    public static function morphMap(): array
    {
        return [Gallery::MORPH_ALIAS => Gallery::class, Slide::MORPH_ALIAS => Slide::class];
    }

    public function createParent(): Gallery
    {
        Relation::requireMorphMap();

        return Gallery::create();
    }

    public function revision(): NamesConcurrencyRevision
    {
        return GalleryRevision::SlidesOrder;
    }

    public function memberClass(): string
    {
        return Slide::class;
    }

    public function parentTable(): string
    {
        return 'galleries';
    }

    public function memberTable(): string
    {
        return 'slides';
    }

    public function foreignKey(): string
    {
        return 'slideable_id';
    }

    public function parentRelation(): string
    {
        return 'slideable';
    }

    public function relationClass(): string
    {
        return MorphMany::class;
    }

    public function associate(Model&Ordered $member, Model $parent): void
    {
        assert($member instanceof Slide);

        $member->slideable()->associate($parent);
    }

    public function stranger(): Model
    {
        return $this->add($this->createParent());
    }

    protected function make(bool $hidden): Slide
    {
        return new Slide(['visible' => ! $hidden]);
    }

    public function item(Model $result): Slide
    {
        assert($result instanceof Slide);

        return $result;
    }

    protected function memberKey(Model $result): string
    {
        assert($result instanceof Slide);

        return $result->id;
    }

    protected function membership(Model $parent): array
    {
        return ['slideable_type' => $parent->getMorphClass(), 'slideable_id' => $parent->getKey()];
    }
}
