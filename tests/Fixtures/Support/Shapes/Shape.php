<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Shapes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\OrderChange;
use Support\Database\Eloquent\Ordering\Placement;
use Support\Database\Eloquent\Ordering\Scopes\SelectsPosition;

abstract class Shape
{
    /**
     * @return iterable<string, array{Shape}>
     */
    public static function all(): iterable
    {
        yield 'has many' => [new HasManyShape];
        yield 'morph many' => [new MorphManyShape];
        yield 'belongs to many' => [new BelongsToManyShape];
        yield 'morph to many' => [new MorphToManyShape];
        yield UuidMorphManyShape::NAME => [new UuidMorphManyShape];
    }

    /**
     * @param  iterable<string, list<mixed>>  $cases
     * @return iterable<string, list<mixed>>
     */
    public static function crossed(iterable $cases): iterable
    {
        $cases = is_array($cases) ? $cases : iterator_to_array($cases);

        foreach (self::all() as $name => [$shape]) {
            foreach ($cases as $case => $arguments) {
                yield "{$name}, {$case}" => [$shape, ...$arguments];
            }
        }
    }

    abstract public function createParent(): Model&OrdersRelations&Revisioned;

    abstract public function revision(): NamesConcurrencyRevision;

    /**
     * @return class-string<Model&Ordered>
     */
    abstract public function memberClass(): string;

    abstract public function parentTable(): string;

    abstract public function memberTable(): string;

    abstract public function foreignKey(): string;

    abstract public function parentRelation(): string;

    /**
     * @return class-string<Relation<Model, Model, mixed>>
     */
    abstract public function relationClass(): string;

    abstract public function associate(Model&Ordered $member, Model $parent): void;

    abstract public function stranger(): Model;

    abstract protected function make(bool $hidden): Model&Ordered;

    abstract public function item(Model $result): Model&Ordered;

    abstract protected function memberKey(Model $result): int|string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function membership(Model $parent): array;

    public function newMember(Model $parent, bool $hidden = false): Model&Ordered
    {
        $member = $this->make($hidden);
        $this->associate($member, $parent);

        return $member;
    }

    public function newUnheldMember(Model $parent): Model&Ordered
    {
        return $this->make(false)->forceFill($this->membership($parent));
    }

    public function add(Model $parent, null|Placement $placement = null, bool $hidden = false): Model&Ordered
    {
        $member = $this->newMember($parent, $hidden);

        if ($placement !== null) {
            $member->placeAt($placement);
        }

        $member->save();

        return $member;
    }

    /**
     * @return array{Model&OrdersRelations&Revisioned, list<Model&Ordered>}
     */
    public function with(int $count): array
    {
        $parent = $this->createParent();
        $members = [];

        for ($index = 0; $index < $count; $index++) {
            $members[] = $this->add($parent);
        }

        return [$parent, $members];
    }

    public function find(int|string $id): Model&Ordered
    {
        $member = $this->memberClass()::query()->withoutGlobalScopes()->withGlobalScope(SelectsPosition::class, new SelectsPosition)->findOrFail($id);

        assert($member instanceof Ordered);

        return $member;
    }

    /**
     * @return HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>
     */
    public static function relationOf(Model $parent, NamesConcurrencyRevision $revision): HasMany|MorphMany|BelongsToMany
    {
        foreach ((new ReflectionClass($parent))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(OrdersRelation::class) as $attribute) {
                if ($attribute->newInstance()->revision === $revision) {
                    $relation = $parent->{$method->getName()}();

                    assert($relation instanceof HasMany || $relation instanceof MorphMany || $relation instanceof BelongsToMany);

                    return $relation;
                }
            }
        }

        throw new LogicException(sprintf('[%s] declares no relation for the [%s] revision.', $parent::class, $revision->value));
    }

    /**
     * @return HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>
     */
    public function relation(Model $parent): HasMany|MorphMany|BelongsToMany
    {
        return self::relationOf($parent, $this->revision());
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function arrange(Model $parent, array $ids, null|string $expected): OrderChange
    {
        return $this->relation($parent)->arrange($ids, $expected);
    }

    /**
     * @return HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>
     */
    public function ordered(Model $parent): HasMany|MorphMany|BelongsToMany
    {
        return self::inOrder($parent, $this->revision());
    }

    /**
     * @return HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>
     */
    public static function inOrder(Model $parent, NamesConcurrencyRevision $revision): HasMany|MorphMany|BelongsToMany
    {
        $relation = self::relationOf($parent, $revision);
        $related = $relation->getRelated();
        $table = $relation instanceof BelongsToMany ? $relation->getTable() : $related->getTable();
        $relation->withoutGlobalScopes(array_values(array_diff(array_keys($related->getGlobalScopes()), [SoftDeletingScope::class, SelectsPosition::class])));

        if ($relation instanceof BelongsToMany) {
            $relation->withPivot('id');
        }

        return $relation->orderBy($table.'.rank_key')->orderBy($table.'.id');
    }

    /**
     * @return list<int|string>
     */
    public function members(Model $parent): array
    {
        return $this->ordered($parent)->get()->map(fn (Model $result): int|string => $this->memberKey($result))->values()->all();
    }

    /**
     * @param  iterable<Model>  $results
     * @return array<int|string, mixed>
     */
    public function selectedPositions(iterable $results): array
    {
        $positions = [];

        foreach ($results as $result) {
            $attributes = $this->item($result)->getAttributes();

            assert(array_key_exists('position', $attributes));

            $positions[$this->memberKey($result)] = $attributes['position'];
        }

        return $positions;
    }

    /**
     * @return array<int|string, null|int>
     */
    public function positions(Model $parent): array
    {
        $positions = [];

        foreach ($this->rows($parent)->whereNull('deleted_at')->pluck('id') as $id) {
            $positions[$id] = $this->find($id)->getAttribute('position');
        }

        asort($positions);

        return $positions;
    }

    public function digest(Model $parent, null|NamesConcurrencyRevision $revision = null): string
    {
        $stored = $parent->fresh();

        assert($stored instanceof Revisioned);

        return $stored->concurrencyRevision($revision ?? $this->revision());
    }

    /**
     * @return array<int|string, null|string>
     */
    public function rankKeys(Model $parent): array
    {
        /** @var array<int|string, null|string> */
        return $this->rows($parent)->orderBy('id')->pluck('rank_key', 'id')->all();
    }

    public function crowd(Model $lower, Model $upper): void
    {
        $prefix = 'a0'.str_repeat('V', 61);

        DB::table($this->memberTable())->where('id', $lower->getKey())->update(['rank_key' => $prefix.'V']);
        DB::table($this->memberTable())->where('id', $upper->getKey())->update(['rank_key' => $prefix.'W']);
    }

    public function longestRankKey(Model $parent): int
    {
        return (int) $this->rows($parent)->max(DB::raw('length(rank_key)'));
    }

    private function rows(Model $parent): Builder
    {
        return DB::table($this->memberTable())->where($this->membership($parent));
    }
}
