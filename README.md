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
    $table->timestamps();
    $table->softDeletes();
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

**The order digest covers the members in order.** A member is a row of the relation with a rank key. The digest query removes every global scope of the related model except `SoftDeletingScope`, and orders by `rank_key`, then by key. A rebalance keeps the order, so it keeps the digest. An empty relation has a digest too. For a pivot relation, the digest covers the related model keys.

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

**A locking read selects the position in a second query.** A row that `lockForUpdate()`, `sharedLock()`, or `refreshForUpdate()` waited on can carry a count from before the wait. So the locking statement selects no `position`. After it returns, one unlocked select by key reads the position of every row it locked.

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

The parent resolves a relation with `orderedRelation()`, and the item with `orderingRelation()`:

```php
$section->orderedRelation(SectionRevision::AssetsOrder);   // OrderedRelation of this parent
$asset->orderingRelation();                                // the OrderedRelation the asset sits in
```

`orderedRelation()` throws `LogicException` when the parent declares no ordered relation for the revision. `orderingRelation()` throws `LogicException` when the item has no parent.

## Supported shapes

| Relation | Item | Example fixture |
|---|---|---|
| `HasMany` | The related model | `Section::assets()` |
| `MorphMany` | The related model | `Tour::scenes()` |
| `BelongsToMany` with `->using()` | The pivot | `Listing::customers()` |
| `MorphToMany` with `->using()` | The pivot | `Tour::customers()` |

**A pivot is the item.** Name an `Ordered` pivot class through `using()`, and give the pivot table its own `rank_key`, a UUID or integer `id`, and `deleted_at`. The member id is the pivot id, not the related model id. `Placement::before()`, `reorder()`, and `OrderChange` all take pivot ids. Hide soft deleted pivots with `wherePivotNull('deleted_at')`.

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

**One model can be both item and parent.** A `Video` sits in a `Section` and orders its own `Clip` items. It uses both traits and declares both attributes. `orderingRelation()` returns the section side. `orderedRelation(VideoRevision::ClipsOrder)` returns the clip side.

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
- a clause filters by an expression, or joins a top-level filter with `or`, because that filter reaches the rows of other parents;
- a pivot relation filters by an unqualified column;
- the relation reads a column that the declaration does not name;
- the relation reads a parent column in any form other than one `where('<item column>', $this->column)`, because the position query must compile the filter for each row.

The foreign key, the morph type, the item's `deleted_at`, and a soft-deleting related model's `deleted_at` need no declaration. The parent's `id` needs none either, but a filter that reads it must take the pair form, such as `where('assets.root_id', $this->id)`. The check reads the relation's clauses before any global scope applies, and `query()` removes every global scope except `SoftDeletingScope`. It finds the parent columns that a relation reads by building the relation once with each parent column set to a probe value. The first check of each parent table runs one column-listing query.

The probe bypasses the column's cast, so `where('tracks.kind', $this->kind)` works when `kind` casts to an enum. A relation that reads the column through an accessor, or reads a property of the cast value such as `$this->kind->value`, throws `LogicException`.

Each position query builds the relation's filters again. A filter on a runtime value, such as `app()->getLocale()`, therefore numbers the members by the value at the time of the query.

A change to a declared column changes the order:

- **An item column.** An Eloquent save locks the parent. `Reordered` reports the item only when it leaves or enters the relation.
- **A parent column.** An Eloquent save of the parent locks it. One `Reordered` lists every member that left and every row that entered. No rank key changes.
- **A bypass.** A query-builder or raw write to a declared column changes the digest and reports nothing.

While it holds the lock, the package writes each stored declared parent column into the parent instance, unless the caller changed that column.

## Reading an order

```php
$assets = $section->orderedRelation(SectionRevision::AssetsOrder);

$assets->query()->get();                 // members in order, scoped by the parent relation
$assets->concurrencyRevision();          // the order digest, one query
```

`query()` returns the parent's relation ordered by `rank_key`, then by key, and drops any order the relation method declares. It removes every global scope of the related model except `SoftDeletingScope`, so an item that a global scope hides still holds its place. For a pivot relation each result carries the pivot id.

**A row the relation excludes keeps its rank key.** A declared `where()` on the relation method excludes rows, and so does a soft delete of a pivot's related model. Such a row has no `position` and cannot be an anchor. It still holds its key in the unique index, so every new key and every rebalance avoids that key, and the row keeps its place.

## A related model of an ordered pivot

A pivot relation drops a pivot whose related model, such as a customer, is soft deleted. That model must use `OrdersThroughPivots`, implement `LocksParentsFirst`, and name in `#[OrderedThrough]` each parent class that orders its pivots:

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

The trait registers the related model's listeners when the model boots, so no configuration lists the parents. A parent throws `LogicException` on its first declaration read when its ordered `BelongsToMany` or `MorphToMany` relation reaches a related model that the order watches, and that model does not use `OrdersThroughPivots` or omits the parent from `#[OrderedThrough]`. The order watches a related model that soft deletes, or whose columns `membership:` names. A related model that the order does not watch needs neither. The trait runs the related model's `save()` and `delete()` in a transaction. It also provides `parentsToLockFirst()`. The check does not require `LocksParentsFirst`, but PHPStan reports a model that uses the trait without it.

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
| `relation` | The `OrderedRelation` that changed. |
| `order` | The id of every member after the write, in order. A member's position is its index plus 1. |
| `changed` | A `PositionChange` for each member that moved: `id`, `position` (`null` when it left this order), and `previousPosition` (`null` when it is new to this order). |
| `revision` | The order digest after the write, read inside the write's transaction. |
| `source` | For a move between parents, the `OrderChange` of the parent the item left. Otherwise `null`. |

After a move between parents, `$asset->orderingRelation()` returns the destination.

A save through Eloquent that changes the parent, such as `$asset->update(['section_id' => $other->id])`, moves the item to the end of the new parent without an `expected` check, so use `move()` when the caller needs one. Such a save is no substitute for ORD-08, which requires an API to return 422 for an update that would move an item to another ordered collection. A save that leaves every parent, such as one that sets a nullable foreign key to `null`, clears the item's rank key.

## Reordering a parent

`reorder()` sets the whole order at once. List every current member exactly once.

```php
$section->orderedRelation(SectionRevision::AssetsOrder)
    ->reorder([$thirdId, $firstId, $secondId], expected: $digest);
```

`expected` is the order revision of this parent. `null` skips the check. A list that omits a member, repeats one, or names a stranger throws `MembershipMismatch` (`InvalidArgumentException`). `reorder()` and an anchor match a UUID id in either letter case, and `OrderChange` reports each id in the database's letter case. A trashed member, a member with no rank key, and a row the relation excludes are not current members. `reorder()` keeps an excluded row directly after the member that precedes it now. It rewrites only the keys that change, and returns an `OrderChange`.

## The order revision in memory

The package writes no revision into a parent instance, and no order write saves a parent row. `concurrencyRevision($revision)` runs the digest query each time, so every instance reads the current order. After a move, a caller who needs the order revision of that write reads `OrderChange::$revision`. A digest read after the commit can include another writer's change.

## Locking

Every write that changes an order first locks the parent rows with `SELECT … FOR NO KEY UPDATE` inside a transaction, and then checks `expected`.

- A create always locks its parent.
- An update locks only when `rank_key`, a foreign key, a morph type, a declared item column, or `deleted_at` is dirty.
- A delete, `move()`, and `reorder()` lock too.
- A save of a parent locks it when a declared parent column is dirty.
- A soft delete, a restore, or a declared-column save of a pivot's related model locks every parent that its pivots reach.
- A create, a restore, a delete, and a watched-column save of a pivot lock its related row `FOR SHARE` after the parent, when the relation filters on the related model. The filter is the related model's `deleted_at` or a declared related column. The pivot write then waits for a related write that has not committed, and reads its result.
- A write that touches two parents locks them in `(morph class, key)` order. Two opposite moves between the same parents therefore never deadlock.

A `move()` re-reads the stored foreign key under the lock. If another transaction moved the item meanwhile, `move()` starts again against the new parent. After 5 such attempts it throws `ContendedOrder`.

**Lock the parents before the item.** Every `Ordered` model implements `LocksParentsFirst` from eloquent-concurrency. `parentsToLockFirst()` returns the parent that the item's original attributes name, then the parent that its current attributes name, each once, and takes no lock. Code that locks an item before it writes it, such as an `If-Match` check, must first lock those parents in `(morph class, key)` order. An item locked before its parent can deadlock with a `reorder()` of that parent.

No attribute value makes `parentsToLockFirst()` throw. It leaves out a parent that does not exist, and returns both parents of attributes that name two. A parent that the item holds on another connection comes back from the item's own connection. The write then throws `LogicException` as before.

**Lock a related model's parents first too.** The `parentsToLockFirst()` of a model that uses `OrdersThroughPivots` returns every parent that its pivot rows reach, soft-deleted pivot rows included. For a model that also uses `IsOrdered`, the list starts with the parents that order the model, and names each parent once. Its soft delete locks the same parents. A related model locked before those parents can deadlock with an `attach()` to one of them.

A model that uses both traits locks that whole list in `(morph class, key)` order before it locks any parent for a single role. A soft delete, a restore, and a save that changes a column the pivot parents watch all lock it this way. Such a write therefore cannot deadlock with a caller that locks the sorted list first.

**A parent that is also an item locks its own parents first.** A save of such a model can change a declared parent column and a column that its own order watches, such as its foreign key. That save locks the sorted `parentsToLockFirst()` list before it locks the model's own row. A `reorder()` of the model's parent locks that parent before it writes the model, so the two writes cannot deadlock. The order of the two traits in the class does not change this.

## Model events

The package saves every row it writes through Eloquent, so each row fires the native `saving`, `updating`, `updated`, and `saved` events. Observers, `Event::listen('eloquent.updated: …')` listeners, and `$dispatchesEvents` classes all receive them.

- **Items.** A `move()`, a `reorder()`, a rebalance, and the soft delete listener each save a fresh copy of every item whose `rank_key` or foreign key they write. The package loads each copy by key on the write connection.
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
| `reorder()` | One. |
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
    $event->relation;   // the OrderedRelation
    $event->revision;   // the order digest after the write
    $event->ids;        // the id of each row whose rank key the rebalance rewrote
});
```

`Rebalanced` implements `ShouldDispatchAfterCommit`. It dispatches once per rebalanced relation per write, directly before that relation's `Reordered`, and carries the revision of that `Reordered`. The rebalance keeps the order, so it does not change the digest. It also dispatches when the write dispatches no `Reordered`, such as a create that the relation excludes. `ids` includes a row that the relation excludes. A rollback or a halt dispatches nothing.

## A write that bypasses Eloquent is unsupported

Write ordered rows through their models. A write through the query builder or raw SQL assigns no rank key, takes no parent lock, dispatches no `Reordered` event, and refreshes no instance held in memory. Nothing refuses it. When it changes an order, it changes the digest, so a later compare against a revision read before it fails. A bypass that lands between a compare and its write is not caught.

```php
Asset::query()->whereKey($id)->update(['title' => 'Dusk']);           // title is not watched: the digest stays
DB::table('assets')->where('id', $id)->update(['rank_key' => 'a1']);  // can change the digest, and reports nothing
Asset::query()->whereKey($id)->delete();                              // changes the digest, and reports nothing
```

**A cascade is a bypass too.** An `ON DELETE CASCADE` from a third table onto an ordered item table deletes items without a lock or a report. Delete the items through Eloquent before the row that they cascade from.

## Limits

- **`restore()` places the item last.** It assigns a new rank key under the parent lock, as a create does, and dispatches one `Reordered`. It discards a key that a bulk soft delete kept, because another member can hold that key now. A restore of a row that another request already restored, or force deleted, changes nothing and dispatches nothing, unless it also changes the parent of a restored row.
- **A reorder or a rebalance of `n` members fires `n` sets of model events.** Each rewritten row fires `saving`, `updating`, `updated`, and `saved`. A move that rebalances saves the moved row twice, so its events fire twice. A listener that must ignore ordering-only changes can read `getChanges()`, or correlate with `Rebalanced`.
- **A package write saves each copy through `Model::save()`.** A `save()` override in the model class does not run for a copy, and the copy fires its model events all the same.
- **`move()` and `reorder()` save fresh copies, not the caller's instances.** An unsaved change on the item or on the parent stays unsaved.
- **`WriteHalted` does not name the event.** A caller cannot tell whether a `saving` or an `updating` listener halted the write.
- **The late-change check of an item has gaps.** An `updating` listener that returns a value other than `null` stops the listeners after it, so the check does not run. A wildcard `updating` listener registered after this package's provider runs after the check, so its change escapes too. A related model has no such gap, because its `updated` listener checks the prepared columns again and refuses an unprepared change.
- **Every model's `creating` and `updating` events run one more listener.** The late-change check costs two `WeakMap` lookups.
- **`reorder()` and a rebalance refresh no item instance in memory.** An instance keeps the `rank_key` it was read with, and a `Revisioned` item keeps its own revision. Reload the item.
- **`attach([a, b])` and `detach([a, b])` dispatch one `Reordered` per pivot.** Each pivot row is one order write, and no batch API exists.
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
- **The membership check inspects the relation's own clauses only.** It does not inspect a join, and it does not refuse a `limit()`. The check runs once per `Class::relation()`, so a relation that branches on instance properties is checked in one branch only.
- **The parent-column probe can miss a read.** A relation that maps the probe value to the same query, such as through an `array` cast that decodes it to `null`, hides that read. A binding that each call builds fresh, such as `now()`, makes every parent column count as read.
- **The tests save a declared item or parent column on a `HasMany` relation only.** For a `MorphMany` or a pivot relation, only the resolution check is tested.
- **A related model reads each parent that `#[OrderedThrough]` names on its first watched write.** It instantiates each parent, reads its method attributes, and builds each declared relation. A name that orders no pivot of the model is skipped without an error.
- **A concurrent attach can escape a related model's soft delete.** Suppose another transaction attaches the related model to a new parent between the pivot read and the write. The soft delete does not lock that parent, and reports nothing to it. An attach that reaches the related row after the write waits for the related commit, so it reports what the related write left.
- **A related model's soft delete without the package's `deleting` listener locks no parent and reports nothing.** A `deleting` listener that runs first and returns a value other than `null` stops the package's listener.
- **A pivot write locks the related row only when the relation filters on it.** No fixture has a pivot relation whose related model has no `deleted_at` and no declared column, so that skip is untested for a pivot write.
- **A pivot order with two live pivots of one related model under one parent is unsupported.** Its digest cannot tell the two pivots apart. Add the partial unique index from [Supported shapes](#supported-shapes) to refuse the second pivot.
- **A hard delete of a related model with pivot rows is unsupported.** Let the pivot table's foreign key refuse it.

## Exceptions

| Exception | Thrown when |
|---|---|
| `StaleConcurrencyRevision` (`RuntimeException`, from eloquent-concurrency) | `expected` does not match the order digest. |
| `MembershipMismatch` (`InvalidArgumentException`) | `reorder()` does not list each current member exactly once. |
| `InvalidAnchor` (`InvalidArgumentException`) | An anchor is not a current member of the parent. |
| `ContendedOrder` (`RuntimeException`) | The item changed parent during each of 5 attempts to lock its order. |
| `UnselectedPosition` (`LogicException`) | Code reads `position` on an item whose query selected none: an explicit select list, a group, a having, `distinct`, a union, `withoutPositions()`, a model's `$withCount`, `withoutGlobalScopes()`, or a `BelongsToMany` paginator or chunk. `isset()` and `??` throw too. The exception names the item. |
| `WriteHalted` (`RuntimeException`) | A `saving` or `updating` listener returned `false` for a copy that an order write saved. The message names the model and its key. The write rolled back. |
| `LogicException` | A relation is misdeclared, a relation filters by a column that its membership does not declare or through a clause that the check cannot verify, a membership declares a timestamp that Eloquent sets, a model is unsaved, an item has no parent, an item and its parent use different connections, a caller set `rank_key`, a save changed the related key of a saved pivot, a listener changed the order or a declared parent column after the package prepared the write, a watched related column changed without a prepared order write, or a relation reads a parent column in a form other than `<item column> = <parent column>`. On a parent's first declaration read: an ordered pivot relation reaches a watched related model that does not use `OrdersThroughPivots`, or that omits the parent from `#[OrderedThrough]`. |
