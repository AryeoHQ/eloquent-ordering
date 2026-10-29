<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Shapes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Support\Database\Eloquent\Concurrency\ConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;
use Support\Database\Eloquent\Concurrency\Contracts\Revisioned;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\OrderedRelation;
use Support\Database\Eloquent\Ordering\Placement;

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
        $member = $this->memberClass()::query()->withoutGlobalScopes()->findOrFail($id);

        assert($member instanceof Ordered);

        return $member;
    }

    public function orderedRelation(Model&OrdersRelations $parent): OrderedRelation
    {
        return $parent->orderedRelation($this->revision());
    }

    /**
     * @return list<int|string>
     */
    public function members(Model&OrdersRelations $parent): array
    {
        return $this->orderedRelation($parent)->query()->get()->map(fn (Model $result): int|string => $this->memberKey($result))->values()->all();
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

    public function storedRevision(Model $parent, null|NamesConcurrencyRevision $revision = null): int
    {
        return (int) DB::table($this->parentTable())->where('id', $parent->getKey())->value($this->column($revision));
    }

    public function setStoredRevision(Model $parent, int $revision): void
    {
        DB::table($this->parentTable())->where('id', $parent->getKey())->update([$this->column() => $revision]);
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
        $prefix = 'a0'.str_repeat('V', OrderedRelation::MAX_KEY_LENGTH - 3);

        DB::table($this->memberTable())->where('id', $lower->getKey())->update(['rank_key' => $prefix.'V']);
        DB::table($this->memberTable())->where('id', $upper->getKey())->update(['rank_key' => $prefix.'W']);
    }

    public function longestRankKey(Model $parent): int
    {
        return (int) $this->rows($parent)->max(DB::raw('length(rank_key)'));
    }

    public function column(null|NamesConcurrencyRevision $revision = null): string
    {
        return ConcurrencyRevision::namedColumn((string) ($revision ?? $this->revision())->value);
    }

    private function rows(Model $parent): Builder
    {
        return DB::table($this->memberTable())->where($this->membership($parent));
    }
}
