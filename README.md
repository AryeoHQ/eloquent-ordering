# eloquent-ordering

Manually ordered Eloquent collections stored as fractional rank keys.

An item holds a `rank_key` string. The items of one parent sort by that string, so a move rewrites one row and never renumbers its siblings. Every order write also raises a **named concurrency revision** on the parent row. A caller reads that revision, and a later move or reorder proves that nobody changed the order in between.

This package builds on [`aryeo/eloquent-concurrency`](https://github.com/AryeoHQ/eloquent-concurrency). Read its README first. It explains own revisions, named revisions, and the triggers that maintain them.

## Requirements

- PHP 8.5 or later.
- Laravel 12.37 or later, or Laravel 13.
- PostgreSQL 14 or later. No other driver is supported. The rank key uses the byte collation `C`, and the order revision depends on PostgreSQL triggers. The eloquent-concurrency schema helpers throw `UnsupportedDriver` on any other connection.

## Installation

```bash
composer require aryeo/eloquent-ordering
php artisan migrate
```

`aryeo/eloquent-concurrency` installs with it. Run its migrations too, because they create the `increment_named_concurrency_revision()` function that every ordering trigger calls.

Laravel discovers `Support\Database\Eloquent\Ordering\Providers\Provider`. The provider registers the `rankKey()` Blueprint macro. The package ships no migration of its own.

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
- `OrderedRelations::createTrigger($table, $parent, $foreignKey, $revision)` increments the named revision of the parent. It fires when a row is inserted or deleted, and when `rank_key`, the foreign key, or `deleted_at` changes. It watches `deleted_at` only when the table has that column.
- `OrderedRelations::dropTrigger($table, $revision)` removes it.

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

**Keys must be named `id`.** The named revision trigger of eloquent-concurrency finds the parent by a column named `id`. The parent's primary key, the item's primary key, and the relation's owner key must therefore all be `id`. For a pivot relation, the parent key and the pivot's primary key count. A relation such as `hasMany(Asset::class, 'section_legacy_id', 'legacy_id')` throws `LogicException` when the relation resolves, because the trigger would raise the revision of another parent.

**A soft-deleting item must use `deleted_at`.** An item whose `DELETED_AT` names another column throws `LogicException` when the relation resolves, because the order trigger and the rank key queries watch `deleted_at`.

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

`IsOrdered` hides `rank_key` from serialization and adds a read-only `position` attribute (1-based, `null` until the item has a place). A model must never write `rank_key` itself. The traits do that.

Three readers fill `position`. Each one counts the rows that the parent's relation returns, as `OrderChange::$order` lists them. A trashed row, a row that the relation excludes, and a row without a rank key have no position.

- **The accessor.** `$asset->position` returns a position that a query or `loadPositions()` selected, and runs no query. Without a selected value, it counts with queries.
- **`withPositions()`** numbers the query that `query()` returns, in one query. The caller's filters, order, limit, and offset apply after the numbering, in either call order. A filter therefore hides rows without renumbering the rest, and page 3 of 15 still starts at position 31. For a pivot relation, the pivot carries `position`. On any other query, `withPositions()` throws `LogicException`.
- **`loadPositions()`** sets `position` on each item of an `OrderedCollection`. It runs one query per item class and relation, so the items can come from several parents and classes. An item with no position gets `null`, and an item that is not `Ordered` throws `LogicException`.

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

Declare `#[CollectedBy(OrderedCollection::class)]` on an item to make its queries return an `OrderedCollection`. Wrap other items, such as the pivots of a relation's results, in `new OrderedCollection($items)`.

A selected `position` is never dirty, and no save writes it. `move()`, a save that changes the parent, a delete, and `restore()` drop it from that instance, so the accessor counts again. A write by another request does not change it.

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

For a `MorphMany` or `MorphToMany`, create the trigger with `createMorphTrigger($table, $parent, $morphName, $morphType, $revision)`. It creates one trigger per parent type, so call it once for each type that orders the table.

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

## Reading an order

```php
$assets = $section->orderedRelation(SectionRevision::AssetsOrder);

$assets->query()->get();                 // members in order, scoped by the parent relation
$assets->concurrencyRevision();          // the stored order revision, read on the write connection
```

`query()` returns the parent's relation ordered by `rank_key`, then by key, and drops any order the relation method declares. It removes every global scope of the related model except `SoftDeletingScope`, so an item that a global scope hides still holds its place. For a pivot relation each result carries the pivot id.

**A row the relation excludes keeps its rank key.** A `where()` on the relation method excludes rows, and so does a soft delete of a pivot's related model. Such a row has no `position` and cannot be an anchor. It still holds its key in the unique index, so every new key and every rebalance avoids that key, and the row keeps its place.

**Soft delete a related model's pivots with it.** An application that soft deletes a pivot's related model, such as a customer, must also soft delete that model's ordered pivots through Eloquent. Otherwise the relation drops the pivot while the order revision and `Reordered` stay silent, and a cached `ETag` goes stale.

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

After each order write, the package reads the new order revision into the parent instances it can reach:

- the parent that the item holds through a loaded relation, such as `$asset->section()->associate($section)`;
- the parent whose relation ran `attach()` or `detach()`;
- the parent whose `orderedRelation()` ran `reorder()`.

It refreshes no other instance. `$section->assets()->create()` and `$section->assets()->save($asset)` set only the foreign key, so the asset holds no parent, and `$section` keeps its old revision. An item loaded by id holds no parent either. After a move, a caller who needs the new order revision reads `OrderChange::$revision` or `$change->relation->parent`. For a create, associate the parent before the save, or reload the parent after it.

A moved item that is itself `Revisioned` reads its revision columns again after the write.

## Locking

Every write that changes an order first locks the parent rows with `SELECT … FOR NO KEY UPDATE` inside a transaction, and then checks `expected`.

- A create always locks its parent.
- An update locks only when `rank_key`, a foreign key, or `deleted_at` is dirty.
- A delete, `move()`, and `reorder()` lock too.
- A write that touches two parents locks them in `(morph class, key)` order. Two opposite moves between the same parents therefore never deadlock.

A `move()` re-reads the stored foreign key under the lock. If another transaction moved the item meanwhile, `move()` starts again against the new parent. After 5 such attempts it throws `ContendedOrder`.

## Model events

The package writes items through the query builder, and the trigger writes the parent. After the last statement of a write, inside its transaction, the package replays Eloquent's update events for each row that it changed:

- an item whose `rank_key` or foreign key a `move()`, a `reorder()`, a rebalance, or the soft delete listener wrote;
- a parent whose order revision the write raised: a `move()`, a `reorder()`, a create or `attach()`, a delete or `detach()`, a `restore()`, and a save that changes the parent.

Each replay fires `saving`, `updating`, `updated`, and `saved` on a fresh instance of the row's model. The instance holds the row read before the write as its original, and the row read after the write as its attributes. `getDirty()` therefore describes the write, and from `updated` on so does `getChanges()`. Observers, `Event::listen('eloquent.updated: …')` listeners, and `$dispatchesEvents` classes all receive the replay. Items replay first, then parents in lock order. One write replays each row once. A row that the caller saves through Eloquent fires its own events and never replays.

The package's item writes also set `updated_at` when the item model uses timestamps. No write touches a parent's `updated_at`, because that change would raise the parent's own revision and fail a concurrent `If-Match`.

- **A halt rolls the write back.** A `saving` or `updating` listener that returns `false` for a replayed instance halts the whole write. The transaction rolls back, no `Reordered` or `Rebalanced` dispatches, and the call throws `WriteHalted`. `save()` and `delete()` throw too, because the halted model is not the one the caller saved.
- **A replayed row cannot change.** A `saving` or `updating` listener that changes an attribute of a replayed instance throws `LogicException`, and the write rolls back.
- After a halt or a refusal, the caller's instance and each parent instance that the write locked keep the state that they had before the write.

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
| `move()` between parents | Two: the source first, then the destination. The destination's `change->source` holds the source change. |
| A save through Eloquent that changes the parent | Two, with the same shapes as a `move()` between parents. One for the source alone when the item leaves every parent. One for the destination alone when the item had no position before the save. None when the destination relation excludes the item. |
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

## A bulk write bypasses Eloquent, and the trigger still counts

Write ordered rows through their models. A write through the query builder or raw SQL is unsupported. Such a write assigns no rank key, dispatches no `Reordered` event, and refreshes no instance held in memory.

The database still increments the order revision for such a write, so a stale `If-Match` cannot pass. The trigger exists for that reason.

```php
Asset::query()->whereKey($id)->update(['title' => 'Dusk']);       // title is not watched: no increment
DB::table('assets')->where('id', $id)->update(['rank_key' => 'a1']);   // +1
Asset::query()->whereKey($id)->delete();                          // soft delete through a query: +1
```

The rule covers application code. The query-builder writes inside `move()`, `reorder()`, and a rebalance are part of the package.

## Limits

- **A model soft delete adds 2 to the order revision.** The delete is one statement, and the `trashed` listener nulls `rank_key` in a second. Tests assert the stored value, never a step size.
- **Revision steps follow the statement count.** A move adds 1 to each affected parent. A reorder that changes `k` keys adds `k + 1`.
- **`restore()` places the item last.** It assigns a new rank key under the parent lock, as a create does, and dispatches one `Reordered`. It discards a key that a bulk soft delete kept, because another member can hold that key now. A restore of a row that another request already restored, or force deleted, changes nothing and dispatches nothing, unless it also changes the parent of a restored row.
- **An unsupported bulk write leaves stale keys.** A soft delete through a query keeps its rank key. A restore through a query keeps that stale key, and fails with `23505` when a live member holds it.
- **A reorder or a rebalance of `n` members fires `n` sets of model events.** Each rewritten row replays `saving`, `updating`, `updated`, and `saved`. A listener that must ignore ordering-only changes can read `getChanges()`, or correlate with `Rebalanced`.
- **A halted `restore()` keeps `deleted_at` null in memory.** `SoftDeletes::restore()` clears it before it calls `save()`, where the package's write starts. Every other attribute keeps its state.
- **`reorder()` and a rebalance refresh no item instance in memory.** An instance keeps the `rank_key` it was read with, and a `Revisioned` item keeps its own revision. Reload the item.
- **`attach([a, b])` and `detach([a, b])` dispatch one `Reordered` per pivot.** Each pivot row is one order write, and no batch API exists.
- **The inverse side of a `MorphToMany` cannot be ordered.** Order it from the side that names the morph type. A `morphedByMany` relation throws `LogicException`.
- **An item's class must match the relation's class exactly.** A subclass of the related model does not resolve. For a pivot relation, the `using()` class must be the item class.
- **The parent key, the owner key, and the item key must be named `id`.** Any other name throws `LogicException`.
- **One parent orders an item class in one relation only.** Declaring two relations for one class, or two relations under one revision, throws `LogicException`.
- **A key longer than 64 characters triggers a rebalance.** The rebalance rewrites every member of the parent, and adds `n + 1` to the revision.
- **The accessor's fallback costs queries.** Without a selected value, `position` counts with queries for each item. Number a list with `withPositions()` or `loadPositions()` instead.
- **A create or delete reads the whole order.** It builds `OrderChange::$order`, which lists every member, so the cost grows with the relation.
- **`Placement::in()` does not work on a create.** Associate the parent instead.
- **A move between parent classes unsets the item's loaded relation** for the old parent. It does not set the new one.
- **A pivot that is never saved has no `id`.** Its `delete()` locks no parent and fires no `trashed`. `detach()` never takes that path.
- **A pivot without `SoftDeletes` hard deletes on `detach()`.** Give every ordered pivot `SoftDeletes`, as the fixtures do.

## Exceptions

| Exception | Thrown when |
|---|---|
| `StaleConcurrencyRevision` (`RuntimeException`, from eloquent-concurrency) | `expected` does not match the stored order revision. |
| `MembershipMismatch` (`InvalidArgumentException`) | `reorder()` does not list each current member exactly once. |
| `InvalidAnchor` (`InvalidArgumentException`) | An anchor is not a current member of the parent. |
| `ContendedOrder` (`RuntimeException`) | The item changed parent during each of 5 attempts to lock its order. |
| `WriteHalted` (`RuntimeException`) | A `saving` or `updating` listener returned `false` for a replayed instance. The message names the model, its key, and the event. The write rolled back. |
| `LogicException` | A relation is misdeclared, a model is unsaved, an item has no parent, an item and its parent use different connections, a listener changed an attribute of a replayed instance, `withPositions()` ran on a query that `OrderedRelation::query()` did not return, or `loadPositions()` met a model that is not `Ordered`. |
