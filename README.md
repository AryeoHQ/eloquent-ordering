# eloquent-ordering

Manually ordered Eloquent collections stored as fractional rank keys.

An item holds a `rank_key` string. The items of one parent sort by that string, so a move rewrites one row and never renumbers its siblings. Each order is a **named concurrency revision** of the parent: a digest of the member keys in order, which PostgreSQL computes on demand. A caller reads that revision, and a later move or reorder proves that nobody changed the order in between.

The package writes every order through Eloquent saves with native model events. No trigger and no revision column take part. A write through the query builder or raw SQL still changes the digest, but nothing refuses it.

This package builds on [`aryeo/eloquent-concurrency`](https://github.com/AryeoHQ/eloquent-concurrency). Read its README first. It explains own revisions, named revisions, and how PostgreSQL computes each digest.

## Requirements

- PHP 8.5 or later.
- Laravel 12.37 or later, or Laravel 13.
- PostgreSQL 14 or later. No other driver is supported. The rank key uses the byte collation `C`, and eloquent-concurrency computes every digest in PostgreSQL.

## Installation

```bash
composer require aryeo/eloquent-ordering
php artisan migrate
```

`aryeo/eloquent-concurrency` installs with it. Run its migrations too, because they install the `concurrency_digest()` function.

Laravel discovers `Support\Database\Eloquent\Ordering\Providers\Provider`. The provider registers the `rankKey()` and `rankKeyIndex()` Blueprint macros and the `withoutPositions()` builder macro. It also registers the listeners that refuse a late change to an order write. The related model of an ordered pivot registers its own listeners when it boots. The package ships no migration of its own.

## Declaring an ordered relation

Two models take part. The **parent** owns the order. The **item** sits in it. Four things connect them.

**1. Name the revision.** An enum that implements `NamesConcurrencyRevision` names the order revision of the parent.

```php
use Support\Database\Eloquent\Concurrency\Contracts\NamesConcurrencyRevision;

enum SectionRevision: string implements NamesConcurrencyRevision
{
    case AssetsOrder = 'assets_order';
}
```

**2. Add the rank key in a migration.** The item gets `rankKey()` and its parent's foreign key. The parent needs no column.

```php
Schema::create('assets', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('section_id')->index()->constrained();
    $table->rankKey();
    $table->string('title')->nullable();
    $table->timestampsTz();
    $table->softDeletesTz();
    $table->rankKeyIndex('section_id');
});
```

- `rankKey()` adds a `rank_key` column: `varchar(64) collate "C" null`.
- `rank_key` is nullable because a row outside every order has no key: a trashed row, a row whose save left every parent, and a row that existed before the package. Such a row is not a member, so it has no position.
- `rankKeyIndex()` adds a unique index on the named parent columns and `rank_key`. It adds `where deleted_at is null` when the table has a `deleted_at` column, because a bulk soft delete keeps its rank key. Name both columns of a morph parent: `rankKeyIndex('sceneable_type', 'sceneable_id')`. The position count reads this index.
- A down migration removes that index by its name: `dropIndex('assets_section_id_rank_key_unique')`. `dropUnique()` does not remove it: on PostgreSQL it drops a constraint of that name, and the macro creates an index, not a constraint.

**3. Declare the relation on the parent.** The parent implements `OrdersRelations` and `Revisioned`, and uses `HasOrderedRelations` and `HasConcurrencyRevisions`. Put `#[OrdersRelation]` on the relation method. It names the revision. It extends eloquent-concurrency's `#[NamedConcurrencyRevision]`, so the parent reads the order digest with `concurrencyRevision()`.

```php
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;

class Section extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;
    use HasUuids;

    /** @return HasMany<Asset, $this> */
    #[OrdersRelation(SectionRevision::AssetsOrder)]
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}

$section->concurrencyRevision(SectionRevision::AssetsOrder);   // '9ab0…', one query
```

The relation method must be public and must declare its return type: `HasMany`, `MorphMany`, or `BelongsToMany`. The related model must be `Ordered`.

**The order digest covers the members in order.** A member is a row of the relation with a rank key. The digest query removes every global scope of the related model except `SoftDeletingScope`, and orders by `rank_key`, then by key. A rebalance keeps the order, so it keeps the digest. An empty relation has a digest too. For a pivot relation, the digest covers the related model keys. The digest covers the members' keys in order, not their content, so it serves as a precondition token for an order write. An API that tags a nested index with it must also cover the members' rendered fields in that tag.

**Keys must be named `id`.** The parent's primary key, the item's primary key, and the relation's owner key must all be `id`. For a pivot relation, the parent key and the pivot's primary key count. A relation such as `hasMany(Asset::class, 'section_legacy_id', 'legacy_id')` throws `LogicException` when the relation resolves.

**A soft-deleting item must use `deleted_at`.** An item whose `DELETED_AT` names another column throws `LogicException` when the relation resolves, because the queries that read and clear rank keys name `deleted_at`.

**4. Declare the item.** The item implements `Ordered` and uses `IsOrdered`. `#[OrderedIn]` is repeatable. It names each parent class.

```php
use Support\Database\Eloquent\Ordering\Attributes\OrderedIn;
use Support\Database\Eloquent\Ordering\Contracts\Ordered;
use Support\Database\Eloquent\Ordering\Provides\IsOrdered;

#[OrderedIn(Section::class)]
class Asset extends Model implements Ordered
{
    use HasUuids;
    use IsOrdered;
    use SoftDeletes;
}
```

`IsOrdered` hides `rank_key` from serialization and adds a read-only `position` attribute (1-based). Only the package's order writes set `rank_key`. A save of an item whose `rank_key` the caller set throws `LogicException`, so move an item with `move()` and place a new one with `placeAt()`. `replicate()` drops the rank key, so a saved replica goes last. An ordered table must have no `position` column, because the package selects a computed column under that name.

**Every query of an item selects its position.** `IsOrdered` boots the `SelectsPosition` global scope. The scope appends one correlated count to the select list, so `$asset->position` comes from the statement that read the row, and runs no query. The count covers every member of the item's parent, whatever the query filters, sorts, or pages. A filter therefore hides rows without renumbering the rest, and page 3 of 15 still starts at position 31. A delete or a move that commits after the read does not change the value that the page renders.

```php
$assets = Asset::query()->where('title', 'like', 'Dusk%')->paginate(15);   // one query, with positions

$assets->first()->position;                                                 // no query
```

The scope covers `get()`, `find()`, the paginators, `chunk()`, route binding, eager and lazy loads, `refresh()`, `fresh()`, `refreshForUpdate()`, `Collection::fresh()`, and a queued job's restored model. A query that joins an ordered pivot table, such as `$listing->customers` or `$customer->listings`, also selects `pivot_position`, so each pivot carries `position`. The scope runs on PostgreSQL only.

**A locking read selects the position in a second query.** A row that `lockForUpdate()`, `sharedLock()`, or `refreshForUpdate()` waited on can carry a count from before the wait. So the locking statement selects no `position`. After it returns, one unlocked select by key reads the position of every row it locked. Outside a transaction, the row locks end with the locking statement, and another write can commit before the second select. Such a read therefore selects no position, and reading `position` throws `UnselectedPosition`. The second select also needs a lock that blocks every update of the row: `lockForUpdate()`, `sharedLock()`, or a `lock()` clause of `for update`, `for no key update`, or `for share`, each with at most `nowait` or `skip locked`. A `for key share` lock blocks only a delete and an update of a column in a unique index without a `where` clause. A soft delete, or a move of an item whose rank-key index is partial, can therefore commit before the second select. That read, and a read under any other lock clause, selects no position either.

**A query without a select list selects the item's own columns only.** The scope turns `select *` into `select <table>.*`, so a join adds no columns of the joined table. Name the joined columns in an explicit select list. That query then carries no `position`.

**The position counts the item among the members of the parent that `#[OrderedIn]` names**, in the order that `OrderChange::$order` lists. `null` means the row is not a member:

- the item is unsaved or trashed, or has no rank key;
- its foreign key names no row, or names a trashed parent;
- a declared relation filter excludes it;
- for a pivot, its related row is trashed, or a declared related column excludes it.

**A query that cannot carry the column selects no position.** A query with an explicit select list, a group, a having, `distinct`, or a union selects none, because the column would break `withCount()`, `whereIn()` subqueries, and grouped reads. Reading `position` on such a row throws `UnselectedPosition`, and so does reading it after `withoutPositions()`. Call `withoutPositions()` on a query that never reads `position`, to skip the count. These common shapes select none too:

- every query of a model with `$withCount`, because `withCount()` sets the select list;
- a query after `withoutGlobalScopes()`, because that call removes the scope;
- `paginate()`, `simplePaginate()`, and `chunk()` on a `BelongsToMany` relation to an ordered model, because the relation sets its select list before the scopes apply.

`isset($item->position)` and `$item->position ?? 0` call the accessor, so they throw on such a row too.

```php
Asset::query()->select(['id', 'title'])->first()->position;   // throws UnselectedPosition
Asset::query()->withoutPositions()->get();                    // no count, no position
```

**A write fills the position of the instance it writes.** A create, `move()`, a save that changes the parent or a declared item column, `restore()`, and a delete set `position` from inside the write's transaction. A selected `position` is never dirty, and no save writes it. A write by another request does not change it, so reload an instance to read its new place.

**A key query selects the position too.** `IsOrdered` overrides `newQueryWithoutScopes()`, which `refresh()`, `fresh()`, `refreshForUpdate()`, and a queue restore build on. A model that takes the same method from another trait, such as Laravel's `HasBuilder`, gets a trait collision. Resolve it in the model:

```php
class Asset extends Model implements Ordered
{
    use HasBuilder;
    use IsOrdered;

    public function newQueryWithoutScopes()
    {
        return $this->selectPositionOnKeyQuery(parent::newQueryWithoutScopes());
    }
}
```

Every order write starts from the item or from the parent's Eloquent relation. `move()` writes through the item. `arrange()`, `moveIn()`, and `createInOrder()` write through the relation:

```php
$asset->move(Placement::first(), expected: $digest);
$section->assets()->arrange([$thirdId, $firstId, $secondId], expected: $digest);
$section->assets()->moveIn([$asset, $other], expected: $digest);
$section->assets()->createInOrder([['name' => 'Front'], ['name' => 'Back']]);
```

Each of these relation writes is a macro on every Eloquent relation. It resolves the declaration by the ordered item class: the related class of a HasMany or MorphMany, and the pivot class of a BelongsToMany or MorphToMany. The relation must also match the declared relation's keys: the foreign key, the owner key, and the morph type and class. A pivot relation must also match the pivot table, the related pivot key, the related table, and the related key. A relation that orders nothing throws `LogicException`.

PHPStan reports a macro call such as `arrange()`, `moveIn()`, `createInOrder()`, or `lockedMembers()` as a dynamic call to a static method. The package registers `tooling/phpstan/rules.neon` under `extra.tooling.phpstan.rules`, so an adopter on `aryeo/tooling-laravel` gets its suppression with no change. Any other PHPStan setup includes it in `phpstan.neon`:

```neon
includes:
    - vendor/aryeo/eloquent-ordering/tooling/phpstan/rules.neon
```

## Supported shapes

| Relation | Item | Example fixture |
|---|---|---|
| `HasMany` | The related model | `Section::assets()` |
| `MorphMany` | The related model | `Tour::scenes()` |
| `BelongsToMany` with `->using()` | The pivot | `Listing::customers()` |
| `MorphToMany` with `->using()` | The pivot | `Tour::customers()` |

**A pivot is the item.** Name an `Ordered` pivot class through `using()`, and give the pivot table its own `rank_key`, a UUID or integer `id`, and `deleted_at`. The member id is the pivot id, not the related model id. `Placement::before()`, `arrange()`, and `OrderChange` all take pivot ids. Hide soft deleted pivots with `wherePivotNull('deleted_at')`.

```php
#[OrderedIn(Listing::class)]
class ListingCustomer extends Pivot implements Ordered
{
    use HasUuids;
    use IsOrdered;
    use SoftDeletes;
}

class Listing extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    /** @return BelongsToMany<Customer, $this, ListingCustomer> */
    #[OrdersRelation(ListingRevision::CustomersOrder)]
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'listing_customer')
            ->using(ListingCustomer::class)
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }
}
```

`attach()` places the new pivot last. `detach()` soft deletes the pivot.

**A pivot order needs at most one live pivot per related row.** The order digest names each member by the related model's key, so it cannot tell two live pivots of one related row apart. Add a partial unique index on `(parent key, related key) where deleted_at is null`, with the morph type column first for a `MorphToMany`, as the `listing_customer` fixture migration does:

```sql
create unique index listing_customer_listing_id_customer_id_unique on listing_customer (listing_id, customer_id) where deleted_at is null
```

**A saved pivot keeps its related model.** A save that changes the related key of a saved pivot, such as `customer_id`, throws `LogicException` and writes nothing. Detach the pivot, then attach the new related model.

**An item can order in several parents.** A `Scene` carries `#[OrderedIn(Tour::class)]` and `#[OrderedIn(Listing::class)]`. Each row resolves to the one parent whose foreign key, and morph type, it names.

**One model can be both item and parent.** A `Video` sits in a `Section` and orders its own `Clip` items. It uses both traits and declares both attributes. `$video->move()` writes the section order. `$video->clips()->arrange()` writes the clip order.

```php
#[OrderedIn(Section::class)]
class Video extends Model implements Ordered, OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;
    use IsOrdered;

    /** @return HasMany<Clip, $this> */
    #[OrdersRelation(VideoRevision::ClipsOrder)]
    public function clips(): HasMany
    {
        return $this->hasMany(Clip::class);
    }
}
```

**A `Revisioned` item leaves `rank_key` out of its own revision.** `IsOrdered` merges `rank_key` into the model's unobserved columns, so a move within a parent, a reorder, and a rebalance keep the item's own digest. A move between parents changes it, because it changes the foreign key. After `move()`, the item holds the own digest of the row that the move saved.

## Filtering an ordered relation

A relation method may filter its members with `where()`. Declare every column such a filter reads in `membership:`, qualified by its table:

```php
class Playlist extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    /** @return HasMany<Track, $this> */
    #[OrdersRelation(PlaylistRevision::TracksOrder, membership: ['tracks.kind', 'playlists.kind'])]
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)->where('tracks.kind', $this->kind);
    }
}
```

An entry names a column of the item table, of the parent table, or, for a pivot relation, of the related table. A related column needs `#[OrderedThrough]` on the related model. See [A related model of an ordered pivot](#a-related-model-of-an-ordered-pivot).

The package checks each relation once per process, when it first resolves. The check throws `LogicException` when:

- a declared entry is not `table.column` on one of those tables;
- a declared entry names the `created_at` or `updated_at` column of a model that uses timestamps, because Eloquent sets that column after the package reads the save's changes;
- the relation's own clauses use a type other than `where()` with an operator, `whereNull()`, `whereNotNull()`, `whereIn()`, `whereNotIn()`, `whereColumn()`, or a nested group of these;
- the relation joins a table, other than the pivot table of a pivot relation, because the position query counts the members without that join;
- a clause filters by an expression, or joins a top-level filter with `or`, because that filter reaches the rows of other parents;
- a `where()` compares a column to an expression, such as `DB::raw('documents.locale')`, because the position query re-aliases the column and not the expression. Compare two columns with `whereColumn()`;
- a pivot relation filters by an unqualified column;
- the relation reads a column that the declaration does not name;
- the relation reads a parent column in any form other than one `where('<item column>', $this->column)`, because the position query must compile the filter for each row;
- with the parent column set to `null`, the relation compiles that pair as anything but `whereNull('<item column>')`. A fallback such as `$this->kind ?? 'audio'` fails this check, and so does a clause that applies only when the column is set;
- a `whereIn()` or `whereNotIn()` subquery reads a table outside its own `from`, such as the item table. The position query counts the members under an alias.

The foreign key, the morph type, the item's `deleted_at`, and a soft-deleting related model's `deleted_at` need no declaration. The parent's `id` needs none either, but a filter that reads it must take the pair form, such as `where('assets.root_id', $this->id)`. The check reads the relation's clauses before any global scope applies, and an order write ignores every global scope except `SoftDeletingScope`. It finds the parent columns that a relation reads by building the relation once with each parent column set to a probe value. The first check of each parent table runs one column-listing query. A relation with a subquery filter runs one `explain` of its position query on PostgreSQL, inside a savepoint.

The probe bypasses the column's cast, so `where('tracks.kind', $this->kind)` works when `kind` casts to an enum. A relation that reads the column through an accessor, or reads a property of the cast value such as `$this->kind->value`, throws `LogicException`.

Each position query builds the relation's filters again. A filter on a runtime value, such as `app()->getLocale()`, therefore numbers the members by the value at the time of the query.

A change to a declared column changes the order:

- **An item column.** An Eloquent save locks the parent. `Reordered` reports the item only when it leaves or enters the relation.
- **A parent column.** An Eloquent save of the parent locks it. One `Reordered` lists every member that left and every row that entered. No rank key changes.
- **A bypass.** A query-builder or raw write to a declared column changes the digest and reports nothing.

While it holds the lock, the package writes each stored declared parent column into the parent instance, unless the caller changed that column.

## Reading an order

```php
$section->assets()->reorder('assets.rank_key')->orderBy('assets.id')->get();   // members in order, each with its position
$section->concurrencyRevision(SectionRevision::AssetsOrder);   // the order digest, one query

$listing->customers()->withPivot('id')->reorder('listing_customer.rank_key')->orderBy('listing_customer.id')->get();   // each pivot carries its id and position
```

Read the members through the parent's Eloquent relation. It keeps the global scopes that the related model declares. `reorder()` replaces any order that the relation method declares. Qualify `rank_key` with the item table, because a pivot query also joins the related table. For a pivot relation, order by the pivot table's `rank_key`, and the pivot carries the position. Select the pivot id with `withPivot('id')`, in the read or in the relation method. Eloquent selects only the two pivot keys, so a pivot read without it has a `null` id, which neither `arrange()` nor an anchor accepts. Each `position` counts the row among every member, including a member that a global scope hides.

**A row the relation excludes keeps its rank key.** A declared `where()` on the relation method excludes rows, and so does a soft delete of a pivot's related model. Such a row has no `position` and cannot be an anchor. It still holds its key in the unique index, so every new key and every rebalance avoids that key, and the row keeps its place.

## A related model of an ordered pivot

Every related model of an ordered pivot relation, such as a customer, must use `OrdersThroughPivots` and implement `LocksParentsFirst`. It must also name in `#[OrderedThrough]` each parent class that orders its pivots:

```php
use Support\Database\Eloquent\Concurrency\Contracts\LocksParentsFirst;
use Support\Database\Eloquent\Ordering\Attributes\OrderedThrough;
use Support\Database\Eloquent\Ordering\Provides\OrdersThroughPivots;

#[OrderedThrough(Listing::class, Tour::class)]
class Customer extends Model implements LocksParentsFirst
{
    use OrdersThroughPivots;
    use SoftDeletes;
}
```

The trait registers the related model's listeners and the `SelectsPosition` scope when the model boots, so no configuration lists the parents. The scope selects `pivot_position` into each relation read that joins the pivot table, from either side. A parent throws `LogicException` on its first declaration read when its ordered `BelongsToMany` or `MorphToMany` relation reaches a misdeclared related model. Such a model does not use `OrdersThroughPivots`, or omits the parent from `#[OrderedThrough]`.

The order watches a related model that soft deletes, because the relation drops its pivots, and a related model whose columns `membership:` names. On a related model that the order does not watch, the listeners lock no parent and report nothing. The trait runs the related model's `save()` and `delete()` in a transaction. It also provides `parentsToLockFirst()`. The check does not require `LocksParentsFirst`, but PHPStan reports a model that uses the trait without it.

An `Ordered` model can also be the related model of an ordered pivot. It then uses both `IsOrdered` and `OrdersThroughPivots`, and implements `Ordered`, which extends `LocksParentsFirst`. Both traits take `parentsToLockFirst()` from one shared trait, so PHP reports no collision.

Three writes of the related model change an order: a soft delete, a restore, and an Eloquent save that changes a declared related column. Each one:

- locks every parent that the model's pivot rows reach, before the write, and reads each pivot's position;
- dispatches one `Reordered` for each pivot that left or entered its relation, after the write.

Soft-deleted pivot rows reach their parents too, so the write locks such a parent and reports nothing to it. A change to an undeclared related column locks nothing. A query-builder write, `deleteQuietly()`, or `restoreQuietly()` of a watched column changes the digest and reports nothing.

A listener that changes a watched related column after the package's `updating` listener throws `LogicException`, and the write rolls back. The refusal holds when that listener also returns a value and stops the listeners after it, because the package's `updated` listener checks the prepared columns again. A watched column that changes with no `updating` listener of the package before it also throws `LogicException`, from the `updated` event. That happens when a wildcard listener registered after the provider changes it, or when an earlier `updating` listener returns a value. The write rolls back in both cases.

## Placing a new item

Associate the parent, then save. The item goes last by default.

```php
$asset = new Asset(['section_id' => $section->id]);
$asset->save();                                                  // last

$asset = (new Asset(['section_id' => $section->id]))
    ->placeAt(Placement::after($anchorId));
$asset->save();                                                  // after the anchor
```

`Placement` has four constructors:

| Constructor | Place |
|---|---|
| `Placement::first()` | Before every member. |
| `Placement::last()` | After every member. |
| `Placement::before($id)` | Before the member `$id`. |
| `Placement::after($id)` | After the member `$id`. |

An anchor must be a current member of the same parent. Otherwise `InvalidAnchor` (`InvalidArgumentException`) is thrown. `placeAt()` throws `LogicException` for a saved item, and for a placement that names a parent. A create takes no `expected`, because it leaves no parent.

**Create several items at once with `createInOrder()`.** It creates each record through the relation, as the relation's `create()` does, in the order given, after every member. It runs in one transaction and dispatches one `Reordered` for the parent, which lists every new member. The keys come from one `FractionalIndex::keysBetween()` call. When another write in the same transaction takes a key at the end of the parent, the remaining keys come from one more call. It returns the created models, as `createMany()` does. For a pivot relation each record holds the related model's attributes, and the relation attaches it. A trashed or missing parent throws `UnavailableParent`, and nothing is created. An empty list returns an empty collection, and locks and checks nothing.

A create that a listener makes during `createInOrder()` joins no batch. It takes the key for its own placement and dispatches its own `Reordered`. A nested `createInOrder()` on the same parent also dispatches its own `Reordered`, which lists only its own records.

## Moving an item

```php
$change = $asset->move(Placement::before($otherId), expected: $digest);              // inside its parent
$change = $asset->move(Placement::last()->in($destination), expected: $digest);      // to another parent
```

`Placement::in($parent)` names the destination parent. The destination must be saved, must order the item's class, and must use the item's connection.

`expected` is the order revision of the **destination**: the parent that the placement's anchor refers to. It is the value a caller read before the move. Inside one parent, the source and the destination are the same parent. A move between parents does not compare the source.

- `expected: $digest` throws `StaleConcurrencyRevision` when the order digest of the destination is not `$digest`. It compares after the parent lock and before the write. It also throws when this instance was read from a parent the item has since left.
- `expected: null` skips both checks. The move starts from wherever the item is now.

Both outcomes leave the data unchanged. A move that keeps the order writes nothing and dispatches nothing.

`move()` returns an `OrderChange`:

| Property | Meaning |
|---|---|
| `parent` | The parent whose order changed. |
| `revision` | The `NamesConcurrencyRevision` that names that order. |
| `order` | The id of every member after the write, in order. A member's position is its index plus 1. |
| `changed` | A `PositionChange` for each member that moved: `id`, `position` (`null` when it left this order), and `previousPosition` (`null` when it is new to this order). |
| `digest` | The order digest after the write, read inside the write's transaction. |
| `source` | For a move between parents, the `OrderChange` of the parent the item left. Otherwise `null`. |

After a move between two parents of one class, the item's loaded parent relation holds the destination. A morph move to a parent of another class unloads that relation instead, so read the destination from `OrderChange::$parent`.

A save through Eloquent that changes the parent, such as `$asset->update(['section_id' => $other->id])`, moves the item to the end of the new parent without an `expected` check, so use `move()` when the caller needs one. Such a save is no substitute for ORD-08, which requires an API to return 422 for an update that would move an item to another ordered collection. A save that leaves every parent, such as one that sets a nullable foreign key to `null`, clears the item's rank key.

## Moving several items

```php
$change = $destination->assets()->moveIn([$third, $first], expected: $digest);                              // last
$change = $destination->assets()->moveIn([$third, $first], expected: $digest, placement: Placement::first());
```

`moveIn(iterable $items, ?string $expected, ?Placement $placement = null)` moves every item to one placement in the relation's parent, in the order given. The items can come from any parents, including this one. `null` places them last. The placement names no parent, because the relation names the destination. Pass the item class: the pivot for a pivot relation.

`expected` is required, and holds the order revision of the destination. It works as `move()`'s does. An item whose instance names a parent that the item no longer has throws `StaleConcurrencyRevision`, as `move()` does. `expected: null` skips both checks and moves each item from its stored parent.

`moveIn()` reads each item's stored parent. It then locks the destination, each source parent, and every ordering parent above them, in the order that `lockedMembers()` uses. Under the locks it re-reads the stored rows of the items and of every ordered model that it locked. It starts again when one of them moved meanwhile. After 5 attempts it throws `ContendedOrder`, which names the destination. Before it writes, it throws `NotAMember` with the id of every item that its order no longer holds. `moveIn()` compares `expected` before this check, so a stale `expected` throws `StaleConcurrencyRevision` instead. `NotAMember` remains for `expected: null` and for a current digest. A trashed or missing destination throws `UnavailableParent`, unless every item is already in it. An anchor among the moved items throws `InvalidAnchor`. An unsaved item, an item of another class or connection, and an item named twice throw `LogicException`.

It returns the destination's `OrderChange`. `changed` lists each moved item whose position in the destination changed, and an item that enters has a `null` `previousPosition`. A named item whose position stays the same is not in `changed`, as in `arrange()`. `source` holds the source's change only when exactly one other parent gave items. With several, `source` is `null`, and each source's change reaches its own `Reordered`. Each item instance gets its new `position`, its new foreign key, and the destination as its loaded parent relation. A call that keeps the order writes nothing and dispatches nothing.

## Reordering a parent

`arrange()` sets the whole order at once. List every current member exactly once.

```php
$section->assets()->arrange([$thirdId, $firstId, $secondId], expected: $digest);
```

The name leaves the query builder's `reorder()` alone, so `$section->assets()->reorder()` still clears the orders of a relation query.

**List the members that a global scope hides.** `arrange()` takes every member, including one that a global scope of the related model hides, but no trashed member. Read that set through the relation without the hiding scopes. Keep `SoftDeletingScope` and `SelectsPosition`:

```php
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Support\Database\Eloquent\Ordering\Scopes\SelectsPosition;

$related = $section->assets()->getRelated();
$hiding = array_diff(array_keys($related->getGlobalScopes()), [SoftDeletingScope::class, SelectsPosition::class]);

$section->assets()->withoutGlobalScopes($hiding)->reorder('assets.rank_key')->orderBy('assets.id')->get();
```

A plain `withoutGlobalScopes()` also drops `SoftDeletingScope`, so the list then names trashed rows and `arrange()` throws `MembershipMismatch`.

**`arrange()` writes the declared relation.** It ignores any constraint that the caller adds to the relation, and checks the list against the members of the declared relation.

`expected` is the order revision of this parent. `null` skips the check. A list that omits a member, repeats one, or names a stranger throws `MembershipMismatch` (`InvalidArgumentException`). `arrange()` and an anchor match a UUID id in either letter case, and `OrderChange` reports each id in the database's letter case. A trashed member, a member with no rank key, and a row the relation excludes are not current members. `arrange()` keeps an excluded row directly after the member that precedes it now. It rewrites only the keys that change, and returns an `OrderChange`.

## The order revision in memory

The package writes no revision into a parent instance, and no order write saves a parent row. `concurrencyRevision($revision)` runs the digest query each time, so every instance reads the current order. After a move, a caller who needs the order revision of that write reads `OrderChange::$digest`. A digest read after the commit can include another writer's change.

## Locking

Every write that changes an order first locks the parent rows with `SELECT … FOR NO KEY UPDATE` inside a transaction, and then checks `expected`.

- A create always locks its parent.
- An update locks only when `rank_key`, a foreign key, a morph type, a declared item column, or `deleted_at` is dirty.
- A delete, `move()`, `moveIn()`, `arrange()`, and `createInOrder()` lock too.
- A save of a parent locks it when a declared parent column is dirty.
- A soft delete, a restore, or a declared-column save of a pivot's related model locks every parent that its pivots reach.
- A create, a restore, a delete, and a watched-column save of a pivot lock its related row `FOR SHARE` after the parent, when the relation filters on the related model. The filter is the related model's `deleted_at` or a declared related column. The pivot write then waits for a related write that has not committed, and reads its result.
- A write that touches two parents locks them in the order that `lockedMembers()` uses: by depth below the root, then in `(morph class, key)` order. It reads the depth only when one of the parents is itself an ordered item or locks parents first. Two opposite moves between the same parents therefore never deadlock.

A `move()` re-reads the stored foreign key under the lock. If another transaction moved the item meanwhile, `move()` starts again against the new parent. After 5 such attempts it throws `ContendedOrder`. If another transaction trashed or deleted the item, or deleted its parent row, `move()` throws `NotAMember` and changes nothing. `move()` compares `expected` before this check, so a stale `expected` throws `StaleConcurrencyRevision` instead. `NotAMember` remains for `expected: null` and for a current digest.

**A trashed or missing parent takes no new member.** A create, a `createInOrder()`, a move into another parent, a save that changes the parent, and a restore check the destination's locked row. When that row is soft deleted, or gone, the write throws `UnavailableParent` and changes nothing. Its `reason` is a `ParentUnavailability` case: `Trashed` or `Missing`. The check reads the row under the lock, so a write that waited on a concurrent delete sees the delete. A parent row that is gone counts as `Missing`, whether the write holds the parent instance or reads it by its key. Items can still leave a trashed parent: a move out of it, a delete from it, a move inside it, and an `arrange()` of it all succeed.

**Lock the parents before the item.** Every `Ordered` model implements `LocksParentsFirst` from eloquent-concurrency. `parentsToLockFirst()` returns the parent that the item's original attributes name, then the parent that its current attributes name, each once, and takes no lock. Code that locks an item before it writes it, such as an `If-Match` check, must first lock those parents by depth below the root, then in `(morph class, key)` order. An item locked before its parent can deadlock with an `arrange()` of that parent.

**Lock a whole flow with `lockedMembers()`.** A flow that reads an order and then writes several parents takes all its parent locks in one call. Aryeo empties a subsection into another one and then deletes it:

```php
DB::transaction(function () use ($subsection, $destination, $ifMatch): void {
    $assets = $subsection->assets()->lockedMembers($destination);

    if ($destination->concurrencyRevision(SectionRevision::AssetsOrder) !== $ifMatch) {
        throw new StaleConcurrencyRevision($destination, SectionRevision::AssetsOrder, $ifMatch);
    }

    $destination->assets()->moveIn($assets, expected: null);

    $subsection->delete();
});
```

The flow compares the destination's `If-Match` once, after `lockedMembers()` holds the locks, so `moveIn()` takes `expected: null`.

`lockedMembers(Model ...$alsoLock)` locks the parent, each model in `$alsoLock`, and every ordering parent above them, up to the root. It reads the parents of each ordered model from its stored row, and the parents that each model's pivot rows reach. It also locks the parents that a model's `parentsToLockFirst()` names, but only the stored parents set the lock order. A held model whose loaded attributes name an old parent therefore cannot change the order. It locks ancestors before descendants: by depth below the root, then in `(morph class, key)` order within one depth. A model's depth is its longest path to a root, so each model locks once, after every parent it has. The call then re-reads the stored foreign keys of every ordered model that it locked. If another transaction moved one of them meanwhile, the call releases its locks and starts again. After 5 such attempts it throws `ContendedOrder`. It then returns the parent's current members in order, with `position` selected. The list includes a member that a global scope hides, and no trashed member. An API must therefore not render the list to a caller, because a tenant scope does not filter it. For a pivot relation it returns the related models, and each `pivot` carries its `position`. Like `arrange()`, it reads the declared relation and ignores a constraint that the caller adds. It refuses no trashed parent. Outside a transaction it throws `LogicException`, because its row locks would end with the statement. Before it locks anything, it throws `LogicException` for a model to lock that is unsaved or uses another connection than the parent, because the transaction cannot hold that lock.

**Take an application lock before the first package write.** A package write locks parents as it runs. A lock that the application takes after it can deadlock with a transaction that takes the same locks in the other order. So take any lock of your own first, and pass every parent that the transaction writes to `lockedMembers()`.

No attribute value makes `parentsToLockFirst()` throw. It leaves out a parent that does not exist, and returns both parents of attributes that name two. A parent that the item holds on another connection comes back from the item's own connection. The write then throws `LogicException` as before.

**Lock a related model's parents first too.** The `parentsToLockFirst()` of a model that uses `OrdersThroughPivots` returns every parent that its pivot rows reach, soft-deleted pivot rows included. For a model that also uses `IsOrdered`, the list starts with the parents that order the model, and names each parent once. Its soft delete locks the same parents. A related model locked before those parents can deadlock with an `attach()` to one of them.

A model that uses both traits locks that whole list by depth below the root, then in `(morph class, key)` order, before it locks any parent for a single role. A soft delete, a restore, and a save that changes a column the pivot parents watch all lock it this way. Such a write therefore cannot deadlock with a caller that locks the sorted list first.

**A parent that is also an item locks its own parents first.** A save of such a model can change a declared parent column and a column that its own order watches, such as its foreign key. That save locks the sorted `parentsToLockFirst()` list before it locks the model's own row. An `arrange()` of the model's parent locks that parent before it writes the model, so the two writes cannot deadlock. The order of the two traits in the class does not change this.

## Model events

The package saves every row it writes through Eloquent, so each row fires the native `saving`, `updating`, `updated`, and `saved` events. Observers, `Event::listen('eloquent.updated: …')` listeners, and `$dispatchesEvents` classes all receive them.

- **Items.** A `move()`, a `moveIn()`, an `arrange()`, a rebalance, and the soft delete listener each save a fresh copy of every item whose `rank_key` or foreign key they write. The package loads each copy by key on the write connection.
- **Parents.** An order write saves no parent row, so a parent fires no event for it. A parent fires its own events only when the caller saves it.
- **Order.** A row that the caller saves through Eloquent, such as a create, a `delete()`, or a `restore()`, fires its own events around the package's item writes.

The package's item saves also set `updated_at` when the item model uses timestamps. For a pivot, the relation decides: the saves set `updated_at` only when the relation declares `withTimestamps()`, so a pivot table without timestamps needs no other setting. An order write touches neither the parent's `updated_at` nor its own digest.

- **A listener's change outside the order is written.** A `saving` or `updating` listener can change a column of a copy, such as `updated_at`, and the save writes that change.
- **A late change to the order is refused.** `IsOrdered` prepares an order write in its own `saving` listener, which the model registers when it boots. A listener that runs after it and changes `rank_key`, the foreign key, the morph type, a pivot's related key, a declared item column, or `deleted_at` throws `LogicException`, and the write rolls back. A later `saving` listener, a `creating` listener, and an `updating` listener are all covered.
- **A late change to a parent's membership is refused.** `HasOrderedRelations` records the declared parent columns, such as `playlists.kind`, in its own `saving` listener. A later listener that changes one of them throws `LogicException`, and the write rolls back.
- **A copy is checked the same way.** A package write records the copy's order columns after it sets them, and before the save. A `saving` or `updating` listener that changes one of them throws `LogicException`, and the whole write rolls back.
- **A halt rolls the write back.** A `saving` or `updating` listener that returns `false` for a copy halts the whole write. The transaction rolls back, no `Reordered` or `Rebalanced` dispatches, and the call throws `WriteHalted`. `save()` and `delete()` throw too.
- After a halt or a refusal, no row changes. The caller's instance and each parent instance keep the state that Eloquent left them in, so reload them. A halted create leaves `exists` true on the caller's instance, although no row exists.

## The `Reordered` event

```php
use Support\Database\Eloquent\Ordering\Events\Reordered;

Event::listen(Reordered::class, function (Reordered $event): void {
    $event->change;   // the OrderChange
});
```

`Reordered` implements `ShouldDispatchAfterCommit`. It fires after the outermost transaction commits, once per affected relation.

| Write | Events |
|---|---|
| `move()` inside one parent | One. |
| `move()` between parents | Two: the source first, then the destination. The destination's `change->source` holds the source change. One for the source alone when the destination relation excludes the item. `move()` then returns the destination change, whose `PositionChange` has a `null` position. |
| A save through Eloquent that changes the parent | Two, with the same shapes as a `move()` between parents. One for the source alone when the item leaves every parent, or when the destination relation excludes the item. One for the destination alone when the item had no position before the save. |
| A save through Eloquent that changes a declared item column | One when the item leaves or enters the relation. None otherwise. |
| A save through Eloquent that changes a declared parent column | One, which lists every member that left and every row that entered. None when every member stays. |
| A soft delete, a restore, or a declared-column save of a pivot's related model | One for each pivot that leaves or enters its relation. |
| `arrange()` | One. |
| `moveIn()` | One for each parent that an item leaves, in lock order, then one for the destination. None for the destination when its relation excludes every item that enters. |
| `createInOrder()` | One, which lists every created member. None when the relation excludes every new row. |
| A create, including a pivot `attach()` | One. None when the relation excludes the new row. |
| A delete, soft or force, including a pivot `detach()` | One. |
| `restore()` | One when the stored row was trashed, and the item enters last. None when the relation excludes the restored row, or when another request already restored or force deleted the row. A restore of a row that another request already restored, and that also changes the parent, counts as a save that changes the parent. |
| A move or reorder that keeps the order | None. |
| A stale move, a halted write, or a rollback | None. |
| Any write that rebalances | Unchanged. `Rebalanced` dispatches first, and the rebalance adds no `PositionChange`. |

## The `Rebalanced` event

A key longer than 64 characters makes the write rebalance the relation first. The rebalance rewrites the rank key of every row in that parent's order. `Rebalanced` announces it.

```php
use Support\Database\Eloquent\Ordering\Events\Rebalanced;

Event::listen(Rebalanced::class, function (Rebalanced $event): void {
    $event->parent;     // the parent whose order the rebalance rewrote
    $event->revision;   // the NamesConcurrencyRevision that names that order
    $event->digest;     // the order digest after the write
    $event->ids;        // the id of each row whose rank key the rebalance rewrote
});
```

`Rebalanced` implements `ShouldDispatchAfterCommit`. It dispatches once per rebalanced relation per write, directly before that relation's `Reordered`, and carries the digest of that `Reordered`. The rebalance keeps the order, so it does not change the digest. It also dispatches when the write dispatches no `Reordered`, such as a create that the relation excludes. `ids` includes a row that the relation excludes. A rollback or a halt dispatches nothing.

## A write that bypasses Eloquent is unsupported

Write ordered rows through their models. A write through the query builder or raw SQL assigns no rank key, takes no parent lock, dispatches no `Reordered` event, and refreshes no instance held in memory. Nothing refuses it. When it changes an order, it changes the digest, so a later compare against a revision read before it fails. A bypass that lands between a compare and its write is not caught.

```php
Asset::query()->whereKey($id)->update(['title' => 'Dusk']);           // title is not watched: the digest stays
DB::table('assets')->where('id', $id)->update(['rank_key' => 'a1']);  // can change the digest, and reports nothing
Asset::query()->whereKey($id)->delete();                              // changes the digest, and reports nothing
```

**A cascade is a bypass too.** An `ON DELETE CASCADE` from a third table onto an ordered item table deletes items without a lock or a report. Delete the items through Eloquent before the row that they cascade from.

## Testing support

**Place a factory-made item with `PlacesOrderedItems`.** Use the trait in the item's factory. `placedAt(Placement $placement)` calls `placeAt()` on each item that the factory makes, so the save puts the item there. The factory places each item of `count(n)` in turn, so `first()` and `after()` give the items in reverse creation order.

```php
use Support\Database\Eloquent\Ordering\Testing\PlacesOrderedItems;

class AssetFactory extends Factory
{
    use PlacesOrderedItems;
}

Asset::factory()->for($section)->placedAt(Placement::before($anchorId))->create();
```

A placement that names a parent throws `LogicException`, as `placeAt()` does.

**Forge a concurrent write with `ChangesRowsOnFirstRead`.** Use the trait in a test case. Each method changes one row through `toBase()` after the first `select` on that row's table and connection, and then ignores every later query. A subquery or a join that reads the table counts as a read of it, and so does a statement that starts with `with`. The match ignores keyword case and whitespace, and it accepts the table quoted or unquoted. It does not parse SQL, so a string literal or a comment that names the table also counts as a read. One forged write runs at a time. A read inside a forged write triggers nothing, so a second forge waits for the next read.

```php
use Support\Database\Eloquent\Ordering\Testing\ChangesRowsOnFirstRead;

$this->deleteOnFirstRead($asset);                     // trashes the row and clears its rank key
$this->moveOnFirstRead($asset->section(), $other);    // points the foreign key at $other, after its last member
```

`deleteOnFirstRead()` deletes the row of a model that does not soft delete. `moveOnFirstRead()` clears every other declared membership of an ordered row, so the row sits in one order only. The forged write runs on the model's connection, inside the test's transaction, when the test opens one. Nothing refuses it, because a write that bypasses Eloquent is unsupported.

## Limits

- **`restore()` places the item last.** It assigns a new rank key under the parent lock, as a create does, and dispatches one `Reordered`. It discards a key that a bulk soft delete kept, because another member can hold that key now. A restore of a row that another request already restored, or force deleted, changes nothing and dispatches nothing, unless it also changes the parent of a restored row.
- **A reorder or a rebalance of `n` members fires `n` sets of model events.** Each rewritten row fires `saving`, `updating`, `updated`, and `saved`. A move that rebalances saves the moved row twice, so its events fire twice. A listener that must ignore ordering-only changes can read `getChanges()`, or correlate with `Rebalanced`.
- **A package write saves each copy through `Model::save()`.** A `save()` override in the model class does not run for a copy, and the copy fires its model events all the same.
- **`move()` and `arrange()` save fresh copies, not the caller's instances.** An unsaved change on the item or on the parent stays unsaved.
- **`WriteHalted` does not name the event.** A caller cannot tell whether a `saving` or an `updating` listener halted the write.
- **A listener that writes to the same order during an order write can fail it.** The package keeps clear of a key that a listener's create, a nested `createInOrder()`, or an `updated` listener during `moveIn()` takes. An `updating` listener on a copy the write is saving, or a listener that rebalances or arranges the same order, can still take a key the write holds. The write then rolls back with a unique violation on `rankKeyIndex()`, and no order changes. Write to the same order after the write returns, or from a queued listener.
- **The late-change check of an item has gaps.** An `updating` listener that returns a value other than `null` stops the listeners after it, so the check does not run. A wildcard `updating` listener registered after this package's provider runs after the check, so its change escapes too. A related model has no such gap, because its `updated` listener checks the prepared columns again and refuses an unprepared change.
- **Every model's `creating` and `updating` events run one more listener.** The late-change check costs two `WeakMap` lookups.
- **`arrange()` and a rebalance refresh no item instance in memory.** An instance keeps the `rank_key` it was read with, and a `Revisioned` item keeps its own revision. Reload the item.
- **`attach([a, b])` and `detach([a, b])` dispatch one `Reordered` per pivot.** Each pivot row is one order write. `createInOrder()` and `moveIn()` are the batch writes, and each dispatches one `Reordered` per parent it changes.
- **The inverse side of a `MorphToMany` cannot be ordered.** Order it from the side that names the morph type. A `morphedByMany` relation throws `LogicException`.
- **An item's class must match the relation's class exactly.** A subclass of the related model does not resolve. For a pivot relation, the `using()` class must be the item class.
- **The parent key, the owner key, and the item key must be named `id`.** Any other name throws `LogicException`.
- **One parent orders an item class in one relation only.** Declaring two relations for one class, or two relations under one revision, throws `LogicException`.
- **A key longer than 64 characters triggers a rebalance.** The rebalance rewrites every member of the parent, and keeps the digest.
- **Every item query runs a correlated count for each row.** The count reads the `rankKeyIndex()` index, so its cost grows with the row's position. Call `withoutPositions()` on a query that never reads `position`.
- **An ordered table cannot have a `position` column.** The scope selects a computed `position`, and a `pivot_position` beside a pivot join, so a stored column of either name conflicts. Drop or rename such a column.
- **The package reserves the scope name `withoutPositions`.** It registers a global `withoutPositions` macro on the Eloquent builder, and a macro runs before a named scope. A model's own `scopeWithoutPositions()` never runs.
- **A declared filter on a parent column must compare one item column with it**, as `where('tracks.kind', $this->kind)` does. The position query compiles that filter as `tracks.kind is not distinct from playlists.kind`, so a `null` parent value matches a `null` item value, as Laravel's `where()` does.
- **A create or delete reads the whole order.** It builds `OrderChange::$order`, which lists every member, so the cost grows with the relation.
- **`Placement::in()` does not work on a create.** Associate the parent instead.
- **A move between parent classes unsets the item's loaded relation** for the old parent. It does not set the new one.
- **A pivot that is never saved has no `id`.** Its `delete()` locks no parent and fires no `trashed`. `detach()` never takes that path.
- **A pivot without `SoftDeletes` hard deletes on `detach()`.** Give every ordered pivot `SoftDeletes`, as the fixtures do.
- **The membership check inspects the relation's own clauses only.** It does not refuse a `limit()`. The check runs once per `Class::relation()`, so a relation that branches on instance properties is checked in one branch only.
- **The parent-column probe can miss a read.** A relation that maps the probe value to the same query, such as through an `array` cast that decodes it to `null`, hides that read. A binding that each call builds fresh, such as `now()`, makes every parent column count as read.
- **The parent-column probe lists a parent table's columns once per connection name and process.** Tenants that share a connection name must share one schema. Otherwise the first tenant's columns decide the check for every tenant.
- **The tests save a declared item or parent column on a `HasMany` relation only.** For a `MorphMany` or a pivot relation, only the resolution check is tested.
- **A related model reads each parent that `#[OrderedThrough]` names on its first watched write.** It instantiates each parent, reads its method attributes, and builds each declared relation. A name that orders no pivot of the model is skipped without an error.
- **A concurrent attach can escape a related model's soft delete.** Suppose another transaction attaches the related model to a new parent between the pivot read and the write. The soft delete does not lock that parent, and reports nothing to it. An attach that reaches the related row after the write waits for the related commit, so it reports what the related write left.
- **A related model's soft delete without the package's `deleting` listener locks no parent and reports nothing.** A `deleting` listener that runs first and returns a value other than `null` stops the package's listener.
- **A pivot write locks the related row only when the relation filters on it.** A related model without `deleted_at` or a declared column is never locked, because no write to it changes the order.
- **A pivot order with two live pivots of one related model under one parent is unsupported.** Its digest cannot tell the two pivots apart. Add the partial unique index from [Supported shapes](#supported-shapes) to refuse the second pivot.
- **A hard delete of a related model with pivot rows is unsupported.** Let the pivot table's foreign key refuse it.

## Exceptions

| Exception | Thrown when |
|---|---|
| `StaleConcurrencyRevision` (`RuntimeException`, from eloquent-concurrency) | `expected` does not match the order digest. |
| `MembershipMismatch` (`InvalidArgumentException`) | `arrange()` does not list each current member exactly once. |
| `InvalidAnchor` (`InvalidArgumentException`) | An anchor is not a current member of the parent. |
| `ContendedOrder` (`RuntimeException`) | The item, the parent passed to `lockedMembers()`, or an item passed to `moveIn()` changed parent during each of 5 attempts to lock its order. For `lockedMembers()` and `moveIn()` the message names the relation's parent. |
| `NotAMember` (`InvalidArgumentException`) | `move()` or `moveIn()` names an item that its order no longer holds, because another transaction trashed or deleted it or deleted its parent row. `class` and `ids` name the items, every one for `moveIn()`. The move changed nothing. |
| `UnavailableParent` (`RuntimeException`) | A create, a `createInOrder()`, a move into another parent, a save that changes the parent, or a restore targets a parent whose locked row is trashed or gone. `parent` names the parent, and `reason` is `ParentUnavailability::Trashed` or `ParentUnavailability::Missing`. The write changed nothing. |
| `UnselectedPosition` (`LogicException`) | Code reads `position` on an item whose query selected none: an explicit select list, a group, a having, `distinct`, a union, `withoutPositions()`, a model's `$withCount`, `withoutGlobalScopes()`, a `BelongsToMany` paginator or chunk, a locking read outside a transaction, or a locking read whose lock lets another transaction update the row. `isset()` and `??` throw too. The exception names the item. |
| `WriteHalted` (`RuntimeException`) | A `saving` or `updating` listener returned `false` for a copy that an order write saved. The message names the model and its key. The write rolled back. |
| `LogicException` | A relation is misdeclared, a relation filters by a column that its membership does not declare or through a clause that the check cannot verify, a membership declares a timestamp that Eloquent sets, a model is unsaved, an item has no parent, an item and its parent use different connections, a caller set `rank_key`, a save changed the related key of a saved pivot, a listener changed the order or a declared parent column after the package prepared the write, a watched related column changed without a prepared order write, a relation reads a parent column in a form other than `<item column> = <parent column>`, a relation filters through a subquery that reads a table outside it, a relation joins a table other than its pivot table, a relation compares a column to an expression in a `where()`, `lockedMembers()` runs outside a transaction or receives a model that is unsaved or on another connection, or `moveIn()` receives an item that is unsaved, of another class or connection, or named twice, or a placement that names a parent. On a parent's first declaration read: an ordered pivot relation reaches a related model that does not use `OrdersThroughPivots`, or that omits the parent from `#[OrderedThrough]`. |
