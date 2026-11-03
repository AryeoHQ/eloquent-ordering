# Upgrading

## From 0.0.1

This release runs on `aryeo/eloquent-concurrency` `0.1.0`, so upgrade both packages together. An order revision is now a digest that PostgreSQL computes on demand. No trigger and no revision column take part. Every query of an item selects its `position`.

Read the [README](README.md) for each replacement. [Adopting on an existing table](README.md#adopting-on-an-existing-table) covers the schema.

### Schema

| 0.0.1 | Now |
|---|---|
| `OrderedRelations::createTrigger()`, `createMorphTrigger()`, `createParentTrigger()`, `createRelatedTrigger()`, `createRelatedMorphTrigger()`, `dropTrigger()`, and `ConcurrencyRevisions::createTrigger()` | Removed. eloquent-concurrency's migration `2026_11_02_000001_drop_the_revision_trigger_functions` drops the trigger functions with `CASCADE`, which removes each installed trigger. Delete these calls from every migration, including the ones that already ran, because a fresh `migrate` runs them again. |
| The `concurrencyRevision()` and `namedConcurrencyRevision()` Blueprint calls, and the revision columns they added | Removed. Delete the calls the same way. Drop `concurrency_revision` and each `<name>_concurrency_revision` in a new migration. No package reads them. |
| `rankKey()` alone | `rankKey()`, then `rankKeyIndex()` with the parent columns. An existing `rank_key` column changes with `$table->rankKey()->change()`. |
| A table column named `position` | Not allowed on an ordered table, because the package selects a computed `position`. Rename or drop it. |

### API

| 0.0.1 | Now |
|---|---|
| `#[OrdersRelation(Revision::X, relation: 'assets')]` on the parent class | `#[OrdersRelation(Revision::X)]` on the `assets()` relation method. |
| `config('concurrency.models')` lists the related models of pivot orders | Removed. The related model uses `OrdersThroughPivots` and names its parents in `#[OrderedThrough]`. |
| `$parent->orderedRelation(Revision::X)->reorder($ids, expected: 4)` | `$parent->assets()->arrange($ids, expected: $digest)`. |
| `$parent->orderedRelation(Revision::X)->query()` | `$parent->assets()`. |
| `$parent->orderedRelation(Revision::X)->concurrencyRevision()` | `$parent->concurrencyRevision(Revision::X)`, a string digest. |
| `$item->orderingRelation()` | Removed. Read the item's own parent relation. |
| `withPositions()` | Removed. Every query selects `position`. |
| `loadPositions()` | Removed. Every query selects `position`. |
| `OrderedCollection` and `#[CollectedBy(OrderedCollection::class)]` | Removed. Use any collection class. |
| `move(Placement, null\|int $expected)` | `move(Placement, null\|string $expected)`. `arrange()` and `moveIn()` also require `expected`. |
| `OrderChange::$relation` and the integer `OrderChange::$revision` | `$parent`, `$revision` (the `NamesConcurrencyRevision`), and `$digest` (the order digest after the write). |
| `Rebalanced::$relation` and the integer `Rebalanced::$revision` | `$parent`, `$revision`, and `$digest`, as on `OrderChange`. |
| `InvalidAnchor` and `MembershipMismatch` take an `OrderedRelation` | Each takes the parent and the `NamesConcurrencyRevision`. |
| `LogicException` for a moved item that is no longer a member | `NotAMember`. |
| A write into a trashed parent succeeds | `UnavailableParent`, with `reason` `trashed` or `missing`. |
| An order write saves the parent, so the parent fires `saving`, `updating`, `updated`, and `saved` | No order write saves the parent, so the parent fires no event for it. |
| A write that bypasses Eloquent fails at commit | Nothing refuses it. It changes the digest and dispatches no `Reordered`. |
| No batch writes | `moveIn()` and `createInOrder()`, each with one `Reordered` per parent it changes. |
| No way to lock a parent and read its members | `lockedMembers()`, inside a transaction. |

**Map `NotAMember` beside `MembershipMismatch`.** Both extend `InvalidArgumentException`, not `LogicException`, so a handler that maps `LogicException` to a 500 does not catch them. `UnavailableParent` extends `RuntimeException`. Map it to a 422 or a 404 by its `reason`.

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
| `expected: null` | A string digest: pass the digest the API received in `If-Match`. `expected` is required, and `null` skips the check. |
