# Upgrading

## From 0.0.1

This release runs on `aryeo/eloquent-concurrency` `0.1.0`, so upgrade both packages together. An order revision is now a digest that PostgreSQL computes on demand. No trigger and no revision column take part. A query of an item selects its `position` unless its shape cannot carry the column. The `UnselectedPosition` row of [Exceptions](README.md#exceptions) lists those shapes, such as a select list other than `*` or `<table>.*`, `distinct`, and a locking read outside a transaction. [Declaring an ordered relation](README.md#declaring-an-ordered-relation) explains each one.

Read the [README](README.md) for each replacement. [Adopting on an existing table](README.md#adopting-on-an-existing-table) covers the schema.

### Schema

| 0.0.1 | Now |
|---|---|
| `OrderedRelations::createTrigger()`, `createMorphTrigger()`, `createParentTrigger()`, `createRelatedTrigger()`, `createRelatedMorphTrigger()`, `dropTrigger()`, and `ConcurrencyRevisions::createTrigger()` | Removed. eloquent-concurrency's migration `2026_11_02_000001_drop_the_revision_trigger_functions` drops the trigger functions with `CASCADE`, which removes each installed trigger. Delete these calls from every migration, including the ones that already ran, because a fresh `migrate` runs them again. |
| The `concurrencyRevision()` and `namedConcurrencyRevision()` Blueprint calls, and the revision columns they added | Removed. Delete the calls the same way. Drop `concurrency_revision` and each `<name>_concurrency_revision` in a new migration, guarded as below. No package reads them. |
| `rankKey()`, and the 0.0.1 README's raw `create unique index <table>_<parent columns>_rank_key_unique` statement | Keep the index on an existing database: `rankKeyIndex()` with the parent columns generates the same name and definition. Add no new index. For a fresh install, replace the raw statement in its original migration with `rankKeyIndex()`. An existing `rank_key` column changes with `$table->rankKey()->change()`. |
| `rankKey()` with no unique index | `rankKey()`, then `rankKeyIndex()` with the parent columns. |
| A table column named `position` | Not allowed on an ordered table, because the package selects a computed `position`. Rename or drop it. |
| A pivot order without a partial unique index on `(parent key, related key) where deleted_at is null` | Required now. Add it before the upgrade, as [Supported shapes](README.md#supported-shapes) shows. Soft delete every live duplicate pivot but one first, or the index creation fails. |

**Guard each column drop.** A fresh `migrate` runs the edited migrations, which no longer add the revision columns, so an unguarded `dropColumn()` fails there:

```php
if (Schema::hasColumn('sections', 'concurrency_revision')) {
    Schema::table('sections', function (Blueprint $table): void {
        $table->dropColumn('concurrency_revision');
    });
}
```

### Deploy order

A fleet that deploys in stages runs 0.0.1 and 0.1.0 side by side for a while. A 0.0.1 process reads the revision columns, so ship the schema change in two deploys:

1. Run the eloquent-concurrency migrations before any 0.1.0 process serves a write, and keep the revision columns. Until those migrations drop the trigger functions, the 0.0.1 triggers refuse every 0.1.0 write with SQLSTATE `23514`, because no 0.1.0 write raises a revision column.
2. Once no 0.0.1 process serves, drop the revision columns in a later deploy.

No 0.1.0 write raises a revision column, so a 0.0.1 process compares against a stale value until it stops. Keep that window short.

`2026_11_02_000001_drop_the_revision_trigger_functions` has an empty `down()`, so a rollback does not restore the trigger functions. A revert to 0.0.1 after that migration runs must reinstall the 0.0.1 trigger migrations. A revert after step 2 must also add the revision columns again.

### API

| 0.0.1 | Now |
|---|---|
| `#[OrdersRelation(Revision::X, relation: 'assets')]` on the parent class | `#[OrdersRelation(Revision::X)]` on the `assets()` relation method. |
| `config('concurrency.models')` lists the related models of pivot orders | Removed. The related model uses `OrdersThroughPivots` and names its parents in `#[OrderedThrough]`. |
| `$parent->orderedRelation(Revision::X)->reorder($ids, expected: 4)` | `$parent->assets()->arrange($ids, expected: $digest)`. |
| `$parent->orderedRelation(Revision::X)->query()` | `$parent->assets()`. |
| `$parent->orderedRelation(Revision::X)->concurrencyRevision()` | `$parent->concurrencyRevision(Revision::X)`, a string digest. |
| `$item->orderingRelation()` | Removed. Read the item's own parent relation. |
| `withPositions()` | Removed. A query selects `position` unless its shape cannot carry the column. A caller that reads `position` from such a query changes the query, or stops reading `position`. |
| `loadPositions()` | Removed. A query selects `position` unless its shape cannot carry the column. A caller that reads `position` from such a query changes the query, or stops reading `position`. |
| `OrderedCollection` and `#[CollectedBy(OrderedCollection::class)]` | Removed. Use any collection class. |
| `move(Placement, null\|int $expected)` | `move(Placement, null\|string $expected)`. `arrange()` and `moveIn()` also require `expected`. |
| `OrderChange::$relation` and the integer `OrderChange::$revision` | `$parent`, `$revision` (the `NamesConcurrencyRevision`), and `$digest` (the order digest after the write). |
| `Rebalanced::$relation` and the integer `Rebalanced::$revision` | `$parent`, `$revision`, and `$digest`, as on `OrderChange`. |
| `InvalidAnchor` and `MembershipMismatch` take an `OrderedRelation` | Each takes the parent and the `NamesConcurrencyRevision`. |
| `LogicException` for a moved item that is no longer a member | `NotAMember` with `expected: null` or a current digest. With a stale `expected`, `move()` and `moveIn()` throw `StaleConcurrencyRevision` first, so an API answers 412 before 422 (OCC-17). |
| A write into a trashed parent succeeds | `UnavailableParent`, with `reason` `trashed` or `missing`. |
| An order write saves the parent, so the parent fires `saving`, `updating`, `updated`, and `saved` | No order write saves the parent, so the parent fires no event for it. |
| A write that bypasses Eloquent fails at commit | Nothing refuses it. It changes the digest and dispatches no `Reordered`. |
| No batch writes | `moveIn()` and `createInOrder()`, each with one `Reordered` per parent it changes. |
| No way to lock a parent and read its members | `lockedMembers()`, inside a transaction. |
| A relation filter that 0.0.1 accepted | Can throw `LogicException` on its first resolve, for one of the five shapes below. See [Filtering an ordered relation](README.md#filtering-an-ordered-relation). |

**Check each relation filter.** The check on a relation's first resolve now also refuses:

- a join of a table other than the pivot table of a pivot relation;
- a `where()` that compares a column to an expression;
- a parent-column read in any form other than `where('<item column>', $this->column)`;
- a `null` parent value that does not compile as `whereNull('<item column>')`, such as `$this->kind ?? 'audio'`;
- a `whereIn()` or `whereNotIn()` subquery that reads a table outside its own `from`.

Each refusal throws `LogicException`, so every request that resolves the relation fails.

**Map `NotAMember` beside `MembershipMismatch`.** `NotAMember`, `MembershipMismatch`, and `InvalidAnchor` extend `InvalidArgumentException`, which extends `LogicException`. A handler that maps `LogicException` to a 500 catches all three. Register their mappings before any general `LogicException` mapping. `InvalidKey` extends `InvalidArgumentException` too, but it reports invalid stored data, so leave it to the 500 mapping. `UnavailableParent` extends `RuntimeException`. Map it to 422 when the request names the parent in its body. Map it to 404 when the request addresses the parent in its path.

**PHPStan.** The relation macros read as dynamic calls to static methods. See the `rules.neon` paragraph in [Declaring an ordered relation](README.md#declaring-an-ordered-relation).

## Aryeo workarounds

AryeoHQ/aryeo #12280 and #12282 adopted 0.0.1 with these workarounds. Each one has a replacement in the package now.

| Aryeo workaround | Replacement |
|---|---|
| `placedOrFail()`, `placed()`, and the page re-run in #12280 (removed in `e7028ea33c`) | Positions in the row. A row that a query returns carries the position from the same statement, and `null` means that the row is not a member. |
| `RefuseTrashedSubsection` / `RefusePlaceAssetsInDeletedSubsection` | `UnavailableParent`, which a create, a move, a parent change, and a restore throw under the parent lock. |
| The per-asset move loop in the Subsection delete | `$default->assets()->moveIn($subsection->assets()->lockedMembers($default), expected: null)`, then `$subsection->delete()`, in the transaction that holds the Job lock. Take the Job lock first. |
| The `placedAt()` factory states | `PlacesOrderedItems` in the factory. |
| `ChangesRowsOnFirstRead` | `Support\Database\Eloquent\Ordering\Testing\ChangesRowsOnFirstRead`. |
| The PlaceAsset Update re-read | The position from the same statement. A package write also fills `position` on the instance it writes. Call `refresh()` to read a write by another request. |
| `MoveToSubsection::liveSubsection()` | `UnavailableParent` from `move()` or `moveIn()`. |
| The schema null-position guards | The `null` contract: `null` means that the row is not a member. A live row that a query returns is never `null` because of a concurrent write. |
| The hand-written `for no key update` in `Delete` | `lockedMembers()`, which locks the parents in the package's order. |
| `extends OrderedCollection` on three collections | Removed. The collections keep their own base class. |
| `loadPositions()` calls | Removed. |
| `expected: null` | A string digest. When the nested index ETag is the order digest, the API passes the unquoted tag from `If-Match`. For `If-Match: *` it passes `null` (OCC-13). `expected` is required, and `null` skips the check. |
