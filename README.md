# eloquent-ordering

Manually ordered Eloquent collections stored as fractional rank keys.

An item holds a `rank_key` string. The items of one parent sort by that string, so a move rewrites one row and never renumbers its siblings. Every order write also raises a **named concurrency revision** on the parent row. A caller reads that revision, and a later move or reorder proves that nobody changed the order in between.

The application raises every revision, through Eloquent saves with native model events. The database only refuses: a commit that changed an order without raising its revision fails.

This package builds on [`aryeo/eloquent-concurrency`](https://github.com/AryeoHQ/eloquent-concurrency). Read its README first. It explains own revisions, named revisions, and the triggers that refuse a revision the application did not raise.

## Requirements

- PHP 8.5 or later.
- Laravel 12.37 or later, or Laravel 13.
- PostgreSQL 14 or later. No other driver is supported. The rank key uses the byte collation `C`, and the refusal of an unraised order revision depends on PostgreSQL triggers. The eloquent-concurrency schema helpers throw `UnsupportedDriver` on any other connection.

## Installation

```bash
composer require aryeo/eloquent-ordering
php artisan migrate
```

`aryeo/eloquent-concurrency` installs with it. Run its migrations too, because they create the `record_named_concurrency_revision()` and `check_named_concurrency_revisions()` functions that every ordering trigger calls.

Laravel discovers `Support\Database\Eloquent\Ordering\Providers\Provider`. The provider registers the `rankKey()` Blueprint macro and the `withPositions` builder macro. It also registers the listeners that refuse a late change to an order write, and the listeners that raise the parents of an ordered pivot's related model. The package ships no migration of its own.

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

**2. Add the columns and triggers in migrations.** The parent gets the named revision column. The item gets `rankKey()`, its parent's foreign key, and the order trigger.

```php
use Support\Database\Eloquent\Concurrency\Schema\ConcurrencyRevisions;
use Support\Database\Eloquent\Ordering\Schema\OrderedRelations;

Schema::create('sections', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('name')->nullable();
    $table->concurrencyRevision();
    $table->namedConcurrencyRevision(SectionRevision::AssetsOrder->value);
    $table->timestamps();
});

ConcurrencyRevisions::createTrigger('sections');

Schema::create('assets', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('section_id')->index()->constrained();
    $table->rankKey();
    $table->string('title')->nullable();
    $table->timestamps();
    $table->softDeletes();
});

Schema::getConnection()->statement(
    'create unique index assets_section_id_rank_key_unique on assets (section_id, rank_key) where deleted_at is null',
);

OrderedRelations::createTrigger('assets', 'sections', 'section_id', SectionRevision::AssetsOrder->value);
```

- `rankKey()` adds a `rank_key` column: `varchar(64) collate "C" null`.
- Add a unique index on the parent key and `rank_key`. Make it partial (`where deleted_at is null`) when the item soft deletes, because a bulk soft delete keeps its rank key.
- `OrderedRelations::createTrigger($table, $parent, $foreignKey, $revision, $membership = [], $relatedKey = null)` installs the order recorders and the commit check on the item table. They watch each insert and delete, and each change to `rank_key`, the foreign key, `deleted_at`, each column that `$membership` names, and the `$relatedKey` column of a pivot table. They watch `deleted_at` only when the table has that column. A write that changes the order and raises no order revision after it fails at commit. See [A write that bypasses Eloquent is refused at commit](#a-write-that-bypasses-eloquent-is-refused-at-commit).
- `OrderedRelations::dropTrigger($table, $revision)` removes every recorder and check of that revision on the table.

**3. Declare the relation on the parent.** The parent implements `OrdersRelations` and `Revisioned`, and uses `HasOrderedRelations` and `HasConcurrencyRevisions`. `#[OrdersRelation]` is repeatable. It names the revision and the relation method.

```php
use Support\Database\Eloquent\Ordering\Attributes\OrdersRelation;
use Support\Database\Eloquent\Ordering\Contracts\OrdersRelations;
use Support\Database\Eloquent\Ordering\Provides\HasOrderedRelations;

#[OrdersRelation(SectionRevision::AssetsOrder, relation: 'assets')]
class Section extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;
    use HasUuids;

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
```

The relation method must be public and must declare its return type: `HasMany`, `MorphMany`, or `BelongsToMany`. The related model must be `Ordered`.

**Keys must be named `id`.** The recorders and the commit check of eloquent-concurrency find the parent by a column named `id`. The parent's primary key, the item's primary key, and the relation's owner key must therefore all be `id`. For a pivot relation, the parent key and the pivot's primary key count. A relation such as `hasMany(Asset::class, 'section_legacy_id', 'legacy_id')` throws `LogicException` when the relation resolves, because the recorder would lock and check the wrong parent row.

**A soft-deleting item must use `deleted_at`.** An item whose `DELETED_AT` names another column throws `LogicException` when the relation resolves, because the order recorder and the rank key queries watch `deleted_at`.

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

`IsOrdered` hides `rank_key` from serialization and adds a read-only `position` attribute (1-based, `null` until the item has a place). Only the package's order writes set `rank_key`. A save of an item whose `rank_key` the caller set throws `LogicException`, so move an item with `move()` and place a new one with `placeAt()`. `replicate()` drops the rank key, so a saved replica goes last. An ordered table must have no `position` column, because the readers and the accessor own that name.

Three readers fill `position`. Each one counts the rows that the parent's relation returns, as `OrderChange::$order` lists them. A trashed row, a row that the relation excludes, and a row without a rank key have no position.

- **The accessor.** `$asset->position` returns a position that a query or `loadPositions()` selected, and runs no query. Without a selected value, it counts with queries.
- **`withPositions()`** numbers the query that `query()` returns, in one query. The caller's filters, order, limit, and offset apply after the numbering, in either call order. A filter therefore hides rows without renumbering the rest, and page 3 of 15 still starts at position 31. For a pivot relation, the pivot carries `position`, and a `select()` must come before `withPositions()`, because a later `select()` replaces that column. A copy of the query, such as `clone $query`, numbers only when `withPositions()` names that copy. On any other query, `withPositions()` throws `LogicException`.
- **`loadPositions()`** sets `position` on each item of an `OrderedCollection`. It loads each item's parent with one query per parent class, and reuses a parent that the item already holds. It then runs one query per item class and relation, so the items can come from several parents and classes. Each item counts among the rows of its own parent's relation, so a declared relation `where()` that reads a parent attribute applies. See [Filtering an ordered relation](#filtering-an-ordered-relation). An item with no position gets `null`, and an item that is not `Ordered` throws `LogicException`.

```php
$assets = $section->orderedRelation(SectionRevision::AssetsOrder)
    ->query()
    ->withPositions()
    ->where('title', 'like', 'Dusk%')
    ->paginate(15);

$results = Asset::query()->where('title', 'like', 'Dusk%')->get()
    ->concat(Scene::query()->where('title', 'like', 'Dusk%')->get())
    ->loadPositions();
```

Declare `#[CollectedBy(OrderedCollection::class)]` on an item to make its queries return an `OrderedCollection`. Wrap other items, such as the pivots of a relation's results, in `new OrderedCollection($items)`. A pivot needs its `id` for `loadPositions()`, so load it through `OrderedRelation::query()`, or through a relation that declares `withPivot('id')`. A pivot without its `id` throws `LogicException`.

A selected `position` is never dirty, and no save writes it. `move()`, a save that changes the parent or a declared item column, a delete, and `restore()` drop it from that instance, so the accessor counts again. A write by another request does not change it.

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

For a `MorphMany` or `MorphToMany`, install the recorders with `createMorphTrigger($table, $parent, $morphName, $morphType, $revision, $membership = [], $relatedKey = null)`. It installs one set per parent type, so call it once for each type that orders the table.

```php
OrderedRelations::createMorphTrigger(
    'scenes', 'tours', 'sceneable', (new Tour)->getMorphClass(), TourRevision::ScenesOrder->value,
);
```

**A pivot is the item.** Name an `Ordered` pivot class through `using()`, and give the pivot table its own `rank_key`, a UUID or integer `id`, and `deleted_at`. The member id is the pivot id, not the related model id. `Placement::before()`, `reorder()`, and `OrderChange` all take pivot ids. Hide soft deleted pivots with `wherePivotNull('deleted_at')`.

```php
#[OrderedIn(Listing::class)]
class ListingCustomer extends Pivot implements Ordered
{
    use HasUuids;
    use IsOrdered;
    use SoftDeletes;
}

#[OrdersRelation(ListingRevision::CustomersOrder, relation: 'customers')]
class Listing extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    /** @return BelongsToMany<Customer, $this, ListingCustomer> */
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

**A saved pivot keeps its related model.** A save that changes the related key of a saved pivot, such as `customer_id`, throws `LogicException` and writes nothing. Detach the pivot, then attach the new related model. Pass the related key to the item recorders, so that the commit refuses a query-builder write to it:

```php
OrderedRelations::createTrigger(
    'listing_customer', 'listings', 'listing_id', ListingRevision::CustomersOrder->value, relatedKey: 'customer_id',
);
```

**An item can order in several parents.** A `Scene` carries `#[OrderedIn(Tour::class)]` and `#[OrderedIn(Listing::class)]`. Each row resolves to the one parent whose foreign key, and morph type, it names.

**One model can be both item and parent.** A `Video` sits in a `Section` and orders its own `Clip` items. It uses both traits and declares both attributes. `orderingRelation()` returns the section side. `orderedRelation(VideoRevision::ClipsOrder)` returns the clip side.

```php
#[OrderedIn(Section::class)]
#[OrdersRelation(VideoRevision::ClipsOrder, relation: 'clips')]
class Video extends Model implements Ordered, OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;
    use IsOrdered;
}
```

A model that is a parent must also carry its own revision column, because `HasConcurrencyRevisions` reads every revision column after a save.

**A `Revisioned` item leaves `rank_key` out of its own revision.** `IsOrdered` merges `rank_key` into the model's unobserved columns, so a move within a parent, a reorder, and a rebalance do not raise the item's own revision. A move between parents does, because it changes the foreign key. Pass the same column to the item's own trigger, or that trigger refuses each order write:

```php
ConcurrencyRevisions::createTrigger('videos', ignore: [OrderedRelation::RANK_KEY]);
```

## Filtering an ordered relation

A relation method may filter its members with `where()`. Declare every column such a filter reads in `membership:`, qualified by its table:

```php
#[OrdersRelation(PlaylistRevision::TracksOrder, relation: 'tracks', membership: ['tracks.kind', 'playlists.kind'])]
class Playlist extends Model implements OrdersRelations, Revisioned
{
    use HasConcurrencyRevisions;
    use HasOrderedRelations;

    /** @return HasMany<Track, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)->where('tracks.kind', $this->kind);
    }
}
```

An entry names a column of the item table, of the parent table, or, for a pivot relation, of the related table. Install a recorder for each side that the declaration names:

```php
OrderedRelations::createTrigger('tracks', 'playlists', 'playlist_id', PlaylistRevision::TracksOrder->value, membership: ['kind']);
OrderedRelations::createParentTrigger('playlists', PlaylistRevision::TracksOrder->value, membership: ['kind']);
```

- `createTrigger()` and `createMorphTrigger()` take the item columns, unqualified.
- `createParentTrigger($table, $revision, $membership)` watches the parent columns. `dropTrigger($table, $revision)` removes it.
- `createRelatedTrigger()` and `createRelatedMorphTrigger()` take the related columns. See [A related model of an ordered pivot](#a-related-model-of-an-ordered-pivot).

The package checks each relation once per process, when it first resolves. The check throws `LogicException` when:

- a declared entry is not `table.column` on one of those tables;
- the relation's own clauses use a type other than `where()` with an operator, `whereNull()`, `whereNotNull()`, `whereIn()`, `whereNotIn()`, `whereColumn()`, or a nested group of these;
- a clause filters by an expression, or joins a top-level filter with `or`, because that filter reaches the rows of other parents;
- a pivot relation filters by an unqualified column;
- the relation reads a column that the declaration does not name.

The foreign key, the morph type, the item's `deleted_at`, and a soft-deleting related model's `deleted_at` need no declaration. The check reads the relation's clauses before any global scope applies, and `query()` removes every global scope except `SoftDeletingScope`. It finds the parent columns that a relation reads by building the relation once with each parent column set to a probe value. The first check of each parent table runs one column-listing query.

A change to a declared column changes the order:

- **An item column.** An Eloquent save locks the parent and raises its order revision. `Reordered` reports the item only when it leaves or enters the relation.
- **A parent column.** An Eloquent save of the parent locks it and raises the order revision in the same `UPDATE`. One `Reordered` lists every member that left and every row that entered. No rank key changes.
- **A bypass.** A query-builder or raw write to a declared column fails at commit, unless the order revision rises after it.

While it holds the lock, the package writes each stored declared parent column into the parent instance, unless the caller changed that column.

## Reading an order

```php
$assets = $section->orderedRelation(SectionRevision::AssetsOrder);

$assets->query()->get();                 // members in order, scoped by the parent relation
$assets->concurrencyRevision();          // the stored order revision, read on the write connection
```

`query()` returns the parent's relation ordered by `rank_key`, then by key, and drops any order the relation method declares. It removes every global scope of the related model except `SoftDeletingScope`, so an item that a global scope hides still holds its place. For a pivot relation each result carries the pivot id.

**A row the relation excludes keeps its rank key.** A declared `where()` on the relation method excludes rows, and so does a soft delete of a pivot's related model. Such a row has no `position` and cannot be an anchor. It still holds its key in the unique index, so every new key and every rebalance avoids that key, and the row keeps its place.

## A related model of an ordered pivot

A pivot relation drops a pivot whose related model, such as a customer, is soft deleted. That model must use `OrdersThroughPivots` and implement `LocksParentsFirst`, so its soft delete and its restore raise each parent they reach:

```php
use Support\Database\Eloquent\Concurrency\Contracts\LocksParentsFirst;
use Support\Database\Eloquent\Ordering\Provides\OrdersThroughPivots;

class Customer extends Model implements LocksParentsFirst
{
    use OrdersThroughPivots;
    use SoftDeletes;
}
```

List each parent in the `models` key of `config/concurrency.php`, which eloquent-concurrency reads. After the application boots, the provider reads the ordered `BelongsToMany` and `MorphToMany` relations of each listed parent. A related model without `OrdersThroughPivots` throws `LogicException` at that point, so every artisan command fails until the model uses the trait. The trait runs the related model's `save()` and `delete()` in a transaction. It also provides `parentsToLockFirst()`. The boot check does not require `LocksParentsFirst`, but PHPStan reports a model that uses the trait without it.

Install a related recorder for each parent that orders the pivots:

```php
OrderedRelations::createRelatedTrigger(
    'customers', 'listings', 'listing_customer', 'customer_id', 'listing_id', ListingRevision::CustomersOrder->value,
);
```

- `createRelatedTrigger($table, $parent, $pivot, $relatedKey, $foreignKey, $revision, $membership = [])` watches the related table's `deleted_at` when the table has one, and each column `$membership` names.
- `createRelatedMorphTrigger($table, $parent, $pivot, $relatedKey, $morphName, $morphType, $revision, $membership = [])` does the same for a `MorphToMany`. Call it once for each parent type.

Three writes of the related model change an order: a soft delete, a restore, and an Eloquent save that changes a declared related column. Each one:

- locks every parent that the model's pivot rows reach, before the write, and reads each pivot's position;
- raises the order revision of each of those parents by 1, after the write, through a save of a fresh copy with native model events;
- dispatches one `Reordered` for each pivot that left or entered its relation.

Soft-deleted pivot rows reach their parents too. A parent that only a soft-deleted pivot row reaches rises without a report. A change to an undeclared related column raises nothing. A query-builder write, `deleteQuietly()`, or `restoreQuietly()` of a watched column fails at commit.

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
$change = $asset->move(Placement::before($otherId), expected: 4);              // inside its parent
$change = $asset->move(Placement::last()->in($destination), expected: 4);      // to another parent
```

`Placement::in($parent)` names the destination parent. The destination must be saved, must order the item's class, and must use the item's connection.

`expected` is the order revision of the **destination**: the parent that the placement's anchor refers to. It is the value a caller read before the move. Inside one parent, the source and the destination are the same parent. A move between parents does not compare the source.

- `expected: 4` throws `StaleConcurrencyRevision` when the stored revision of the destination is not `4`. It also throws when this instance was read from a parent the item has since left.
- `expected: null` skips both checks. The move starts from wherever the item is now.

Both outcomes leave the data unchanged. A move that keeps the order writes nothing and dispatches nothing.

`move()` returns an `OrderChange`:

| Property | Meaning |
|---|---|
| `relation` | The `OrderedRelation` that changed. |
| `order` | The id of every member after the write, in order. A member's position is its index plus 1. |
| `changed` | A `PositionChange` for each member that moved: `id`, `position` (`null` when it left this order), and `previousPosition` (`null` when it is new to this order). |
| `revision` | The stored order revision after the write. |
| `source` | For a move between parents, the `OrderChange` of the parent the item left. Otherwise `null`. |

After a move between parents, `$asset->orderingRelation()` returns the destination.

A save through Eloquent that changes the parent, such as `$asset->update(['section_id' => $other->id])`, moves the item to the end of the new parent without an `expected` check, so use `move()` when the caller needs one. Such a save is no substitute for ORD-08, which requires an API to return 422 for an update that would move an item to another ordered collection. A save that leaves every parent, such as one that sets a nullable foreign key to `null`, clears the item's rank key.

## Reordering a parent

`reorder()` sets the whole order at once. List every current member exactly once.

```php
$section->orderedRelation(SectionRevision::AssetsOrder)
    ->reorder([$thirdId, $firstId, $secondId], expected: 4);
```

`expected` is the order revision of this parent. `null` skips the check. A list that omits a member, repeats one, or names a stranger throws `MembershipMismatch` (`InvalidArgumentException`). `reorder()` and an anchor match a UUID id in either letter case, and `OrderChange` reports each id in the database's letter case. A trashed member, a member with no rank key, and a row the relation excludes are not current members. `reorder()` keeps an excluded row directly after the member that precedes it now. It rewrites only the keys that change, and returns an `OrderChange`.

## The order revision in memory

After each order write, the package writes the raised order revision into the parent instances it can reach:

- the parent that the item holds through a loaded relation, such as `$asset->section()->associate($section)`;
- the parent whose relation ran `attach()` or `detach()`;
- the parent whose `orderedRelation()` ran `reorder()`.

It refreshes no other instance. `$section->assets()->create()` and `$section->assets()->save($asset)` set only the foreign key, so the asset holds no parent, and `$section` keeps its old revision. An item loaded by id holds no parent either. After a move, a caller who needs the new order revision reads `OrderChange::$revision` or `$change->relation->parent`. For a create, associate the parent before the save, or reload the parent after it.

The package saves a fresh copy of each parent, so the parent's model events fire on that copy, not on these instances. A moved item that is itself `Revisioned` reads its revision columns again after the write.

## Locking

Every write that changes an order first locks the parent rows with `SELECT … FOR NO KEY UPDATE` inside a transaction, and then checks `expected`.

- A create always locks its parent.
- An update locks only when `rank_key`, a foreign key, a morph type, a declared item column, or `deleted_at` is dirty.
- A delete, `move()`, and `reorder()` lock too.
- A save of a parent locks it when a declared parent column is dirty.
- A soft delete, a restore, or a declared-column save of a pivot's related model locks every parent that its pivots reach.
- A create of a pivot locks its related row `FOR SHARE` after the parent, when the relation filters on the related model. The filter is the related model's `deleted_at` or a declared related column. The create then waits for a related write that has not committed, and reads its result.
- A write that touches two parents locks them in `(morph class, key)` order. Two opposite moves between the same parents therefore never deadlock.

A `move()` re-reads the stored foreign key under the lock. If another transaction moved the item meanwhile, `move()` starts again against the new parent. After 5 such attempts it throws `ContendedOrder`.

**Lock the parents before the item.** Every `Ordered` model implements `LocksParentsFirst` from eloquent-concurrency. `parentsToLockFirst()` returns the parent that the item's original attributes name, then the parent that its current attributes name, each once, and takes no lock. Code that locks an item before it writes it, such as an `If-Match` check, must first lock those parents in `(morph class, key)` order. An item locked before its parent can deadlock with a `reorder()` of that parent.

No attribute value makes `parentsToLockFirst()` throw. It leaves out a parent that does not exist, and returns both parents of attributes that name two. A parent that the item holds on another connection comes back from the item's own connection. The write then throws `LogicException` as before.

**Lock a related model's parents first too.** The `parentsToLockFirst()` of a model that uses `OrdersThroughPivots` returns every parent that its pivot rows reach, soft-deleted pivot rows included. Its soft delete locks the same parents. A related model locked before those parents can deadlock with an `attach()` to one of them.

## Model events

The package saves every row it writes through Eloquent, so each row fires the native `saving`, `updating`, `updated`, and `saved` events. Observers, `Event::listen('eloquent.updated: …')` listeners, and `$dispatchesEvents` classes all receive them.

- **Items.** A `move()`, a `reorder()`, a rebalance, and the soft delete listener each save a fresh copy of every item whose `rank_key` or foreign key they write. The package loads each copy by key on the write connection.
- **Parents.** Each write raises the order revision of every parent whose order it changed, by exactly 1. It saves a fresh copy of the parent, and the dirty value of the revision column is an `Expression` that adds 1, not an integer.
- **Order.** Items fire first, then parents in lock order. A row that the caller saves through Eloquent, such as a create, a `delete()`, or a `restore()`, fires its own events. Its parents rise inside its `created`, `saved`, or `deleted` event.

The package's item saves also set `updated_at` when the item model uses timestamps. For a pivot, the relation decides: the saves set `updated_at` only when the relation declares `withTimestamps()`, so a pivot table without timestamps needs no other setting. A parent copy saves without timestamps and changes only the order revision, so a raise touches neither the parent's `updated_at` nor its own revision.

- **A listener's change outside the order is written.** A `saving` or `updating` listener can change a column of a copy, such as `updated_at`, and the save writes that change.
- **A late change to the order is refused.** `IsOrdered` prepares an order write in its own `saving` listener, which the model registers when it boots. A listener that runs after it and changes `rank_key`, the foreign key, the morph type, a pivot's related key, a declared item column, or `deleted_at` throws `LogicException`, and the write rolls back. A later `saving` listener, a `creating` listener, and an `updating` listener are all covered.
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
    $event->revision;   // the order revision after the write
    $event->ids;        // the id of each row whose rank key the rebalance rewrote
});
```

`Rebalanced` implements `ShouldDispatchAfterCommit`. It dispatches once per rebalanced relation per write, directly before that relation's `Reordered`, and carries the revision of that `Reordered`. It also dispatches when the write dispatches no `Reordered`, such as a create that the relation excludes. `ids` includes a row that the relation excludes. A rollback or a halt dispatches nothing.

## A write that bypasses Eloquent is refused at commit

Write ordered rows through their models. A write through the query builder or raw SQL assigns no rank key, dispatches no `Reordered` event, and refreshes no instance held in memory. The database refuses its commit, unless the order revision rises after it.

Recorders watch every table whose writes can change an order: the item table, a parent table with declared columns, and a related table. After each statement that changes a watched row, a recorder locks each affected parent `FOR NO KEY UPDATE` and records its order revision. At commit, a deferred check raises SQLSTATE `23514` when a recorded parent that still exists holds an order revision no higher than the recorded one. The package raises each revision after its item writes, in the same transaction, so its own writes commit.

```php
Asset::query()->whereKey($id)->update(['title' => 'Dusk']);           // title is not watched: commits
DB::table('assets')->where('id', $id)->update(['rank_key' => 'a1']);  // refused at commit
Asset::query()->whereKey($id)->delete();                              // soft delete through a query: refused at commit
```

**A cascade is a bypass too.** An `ON DELETE CASCADE` from a third table onto an ordered item table deletes items without a raise, so the commit fails. Delete the items through Eloquent before the row that they cascade from. A cascade from the parent itself commits, because the check skips a parent that no longer exists.

## Limits

- **`restore()` places the item last.** It assigns a new rank key under the parent lock, as a create does, and dispatches one `Reordered`. It discards a key that a bulk soft delete kept, because another member can hold that key now. A restore of a row that another request already restored, or force deleted, changes nothing and dispatches nothing, unless it also changes the parent of a restored row.
- **A bypass that raises the order revision itself commits.** The check compares revisions only, so it cannot tell such a write from a package write. The bypass can still leave stale keys and dispatch no `Reordered`.
- **A reorder or a rebalance of `n` members fires `n` sets of model events.** Each rewritten row fires `saving`, `updating`, `updated`, and `saved`. A move that rebalances saves the moved row twice, so its events fire twice. A listener that must ignore ordering-only changes can read `getChanges()`, or correlate with `Rebalanced`.
- **A package write saves each copy through `Model::save()`.** A `save()` override in the model class does not run for a copy, and the copy fires its model events all the same.
- **`move()` and `reorder()` save fresh copies, not the caller's instances.** An unsaved change on the item or on the parent stays unsaved.
- **`WriteHalted` does not name the event.** A caller cannot tell whether a `saving` or an `updating` listener halted the write.
- **The late-change check of an item has gaps.** An `updating` listener that returns a value other than `null` stops the listeners after it, so the check does not run. A wildcard `updating` listener registered after this package's provider runs after the check, so its change escapes too. The commit check still refuses a change to a watched column that no raise follows. A related model has no such gap, because its `updated` listener checks the prepared columns again and refuses an unprepared change.- **Every model's `creating` and `updating` events run one more listener.** The late-change check costs two `WeakMap` lookups.
- **`reorder()` and a rebalance refresh no item instance in memory.** An instance keeps the `rank_key` it was read with, and a `Revisioned` item keeps its own revision. Reload the item.
- **`attach([a, b])` and `detach([a, b])` dispatch one `Reordered` per pivot.** Each pivot row is one order write, and no batch API exists.
- **The inverse side of a `MorphToMany` cannot be ordered.** Order it from the side that names the morph type. A `morphedByMany` relation throws `LogicException`.
- **An item's class must match the relation's class exactly.** A subclass of the related model does not resolve. For a pivot relation, the `using()` class must be the item class.
- **The parent key, the owner key, and the item key must be named `id`.** Any other name throws `LogicException`.
- **One parent orders an item class in one relation only.** Declaring two relations for one class, or two relations under one revision, throws `LogicException`.
- **A key longer than 64 characters triggers a rebalance.** The rebalance rewrites every member of the parent. The write still raises the order revision by 1.
- **The accessor's fallback costs queries.** Without a selected value, `position` counts with queries for each item. Number a list with `withPositions()` or `loadPositions()` instead.
- **An ordered table cannot have a `position` column.** The readers select `position`, and the accessor returns any `position` attribute it finds, so a stored column hides the computed position. Drop or rename such a column.
- **The package reserves the scope name `withPositions`.** It registers a global `withPositions` macro on the Eloquent builder, and a macro runs before a named scope. A model's own `scopeWithPositions()` never runs.
- **A create or delete reads the whole order.** It builds `OrderChange::$order`, which lists every member, so the cost grows with the relation.
- **`Placement::in()` does not work on a create.** Associate the parent instead.
- **A move between parent classes unsets the item's loaded relation** for the old parent. It does not set the new one.
- **A pivot that is never saved has no `id`.** Its `delete()` locks no parent and fires no `trashed`. `detach()` never takes that path.
- **A pivot without `SoftDeletes` hard deletes on `detach()`.** Give every ordered pivot `SoftDeletes`, as the fixtures do.
- **The membership check inspects the relation's own clauses only.** It does not inspect a join, and it does not refuse a `limit()`. The check runs once per `Class::relation()`, so a relation that branches on instance properties is checked in one branch only.
- **The parent-column probe can miss a read.** A relation that maps the probe value to the same query, such as through an `array` cast that decodes it to `null`, hides that read. A binding that each call builds fresh, such as `now()`, makes every parent column count as read.
- **The tests save a declared item or parent column on a `HasMany` relation only.** For a `MorphMany` or a pivot relation, only the resolution check is tested.
- **The related model of a listed parent's pivots always needs `OrdersThroughPivots`.** The boot check is literal: a related model with no `deleted_at` and no declared column still needs the trait, and gets no listeners.
- **Each boot reads every listed parent.** The provider instantiates each `OrdersRelations` model in `concurrency.models`, reads its attributes, and builds each declared relation. It runs no query.
- **A concurrent attach can refuse a related model's commit.** Suppose another transaction attaches the related model to a new parent between the pivot read and the write. The recorder records that parent, and the listener does not raise it, so the commit fails with `23514`. A retry succeeds. An attach that reaches the related row after the write waits for the related commit, so it reports what the related write left.
- **A related model's soft delete without the package's `deleting` listener raises without a report.** A `deleting` listener that runs first and returns a value other than `null` stops the package's listener. The `trashed` listener then raises each parent after the write, and dispatches no `Reordered`.
- **An attach locks the related row only when the relation filters on it.** No fixture has a pivot relation whose related model has no `deleted_at` and no declared column, so that skip is untested.
- **Two live pivots of one related model under one parent dispatch two `Reordered` events** for that parent. A unique index on the pivot's two foreign keys prevents it.
- **A hard delete of a related model with pivot rows is unsupported.** Let the pivot table's foreign key refuse it.
- **`dropTrigger()` on a related table drops each recorder of that revision.** `OrderedRelations::dropTrigger('customers', 'customers_order')` drops the recorder of every parent whose revision is named `customers_order`.

## Exceptions

| Exception | Thrown when |
|---|---|
| `StaleConcurrencyRevision` (`RuntimeException`, from eloquent-concurrency) | `expected` does not match the stored order revision. |
| `MembershipMismatch` (`InvalidArgumentException`) | `reorder()` does not list each current member exactly once. |
| `InvalidAnchor` (`InvalidArgumentException`) | An anchor is not a current member of the parent. |
| `ContendedOrder` (`RuntimeException`) | The item changed parent during each of 5 attempts to lock its order. |
| `WriteHalted` (`RuntimeException`) | A `saving` or `updating` listener returned `false` for a copy that an order write saved. The message names the model and its key. The write rolled back. |
| `LogicException` | A relation is misdeclared, a relation filters by a column that its membership does not declare or through a clause that the check cannot verify, a model is unsaved, an item has no parent, an item and its parent use different connections, a caller set `rank_key`, a save changed the related key of a saved pivot, a listener changed the order after the package prepared the write, a watched related column changed without a prepared order write, `withPositions()` ran on a query that `OrderedRelation::query()` did not return, or `loadPositions()` met a model that is not `Ordered` or a pivot without its `id`. At boot: a parent in `concurrency.models` orders the pivots of a model that does not use `OrdersThroughPivots`. |
| SQLSTATE `23514` | A write changed an order, and no raise of its order revision followed. The commit throws `PDOException`. A statement outside a transaction commits by itself, so it throws `QueryException`. |
