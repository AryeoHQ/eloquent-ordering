# eloquent-ordering
Manually ordered Eloquent collections stored as fractional rank keys

A parent model owns an ordered collection of items. Each item stores a private rank key, and the package derives a read-only, 1-based `position` from it. A counter on the parent holds the version of the order. Every change of order compares and increments that counter in one statement:

```sql
update "asset_subsections" set "assets_order_version" = "assets_order_version" + 1
where "id" = ? and "assets_order_version" = ?
```

No affected row means another write changed the order first. That statement also locks the parent row, so two writers never generate rank keys at the same time. The package has no HTTP dependency. It exposes the version and the order tag as plain values.

## Declare the parent and its items

The parent implements `OrdersCollection` and declares each ordered collection as a `HasMany` relation:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Support\Ordering\Contracts\OrdersCollection;
use Support\Ordering\Provides\HasOrderedCollections;

class AssetSubsection extends Model implements OrdersCollection
{
    use HasOrderedCollections;

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
```

The item implements `Ordered`. It names its parent relation and the parent's collection:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Support\Ordering\Contracts\Ordered;
use Support\Ordering\Provides\IsOrdered;

class Asset extends Model implements Ordered
{
    use IsOrdered;

    /** @return BelongsTo<AssetSubsection, $this> */
    public function assetSubsection(): BelongsTo
    {
        return $this->belongsTo(AssetSubsection::class);
    }

    /** @return BelongsTo<AssetSubsection, $this> */
    public function orderedParent(): BelongsTo
    {
        return $this->assetSubsection();
    }

    public function orderedCollectionName(): string
    {
        return 'assets';
    }
}
```

The rank key lives in `rank_key`. Override `getRankKeyName()` to use another column. `IsOrdered` hides the rank key from `toArray()` and `toJson()`, because an API must not expose how it stores an order (ORD-06).

## Migrations

`rankKey()` adds the rank key column to the item table. `orderVersion()` adds the counter to the parent table:

```php
Schema::create('asset_subsections', function (Blueprint $table) {
    $table->id();
    $table->orderVersion('assets'); // assets_order_version: unsigned bigint, default 0
});

Schema::create('assets', function (Blueprint $table) {
    $table->id();
    $table->foreignId('asset_subsection_id')->constrained();
    $table->rankKey();              // rank_key: varchar(64), byte order

    $table->unique(['asset_subsection_id', 'rank_key']);
});
```

Every ordered collection requires that unique index on the foreign key and the rank key. `rankKey()` sets a byte-order collation: `COLLATE "C"` on PostgreSQL, `utf8mb4_bin` on MySQL and MariaDB, and the default `BINARY` on SQLite. It sets no collation on any other driver.

## Place a new member

A new member takes the last position, unless you place it (ORD-11):

```php
use Support\Ordering\Placement;

$asset->placeAt(Placement::first())->save();
$asset->placeAt(Placement::before($anchorId))->save();
$asset->placeAt(Placement::after($anchorId))->save();
```

An anchor that is not a current member of the collection throws `Support\Ordering\Exceptions\InvalidAnchor` (ORD-12). A create and a delete each increment the version once, and neither takes an expected version (ORD-13).

`$asset->position` counts every member of the collection, whatever a global scope hides (ORD-03). Each read runs one query.

## Move and reorder

`orderedCollection()` returns the collection, and `move()` and `reorder()` change its order:

```php
$assets = $subsection->orderedCollection('assets');

$change = $assets->move($asset, Placement::after($anchorId), $expectedVersion);
$change = $assets->reorder([$thirdId, $firstId, $secondId], $expectedVersion);
```

- `move()` throws `InvalidAnchor` for an anchor that is the moved item or not a current member (ORD-23).
- `move()` also accepts an item of another parent's collection. It removes the item from that collection and changes both versions in one transaction (ORD-24).
- `reorder()` takes the id of every member once, in the new order. It throws `MembershipMismatch` for a missing, repeated, or foreign id (ORD-19).
- Both throw `StaleOrder` when the order is no longer at `$expectedVersion`.

A rejected move or reorder changes nothing.

A rank key never exceeds 64 characters. A write that would exceed that limit first rebalances every key of the collection evenly, in the same transaction. A rebalance changes no position, no version, and no tag.

## Versions and tags

`version()` returns the current version of the order. `tag()` returns the strong order tag, a quoted xxh128 hash of the parent's morph class, the parent's key, the collection name, and the version. The tag changes with every change of order or membership, and with nothing else.

Read the version through `version()`. The package does not update the counter attribute of a parent model you hold.

## Results

`move()` and `reorder()` return an `OrderChange`, which holds every field of a `reordered` event:

| Property | Value |
|---|---|
| `collection` | The `OrderedCollection` that changed, with its `parent` and `name` |
| `order` | Every member as `['id' => …, 'position' => …]`, in order of position |
| `changed` | Each moved item as `['id' => …, 'position' => …, 'previous_position' => …]` |
| `version` | The version after the change |
| `tag` | The order tag at that version |
| `source` | For a move between two parents, the `OrderChange` of the collection the item left; otherwise `null` |

The moved items are the item of a move, and each member whose position a reorder changes. A member that joins the collection has a `null` `previous_position`, and a member that leaves it has a `null` `position` (ORD-34 to ORD-36).

## Write every order change through the package

The counter and the rank keys stay correct only while every order write goes through this package:

- Create and delete items through Eloquent models, and move them with `OrderedCollection`. A query builder insert, update, or delete skips the counter and the rank key.
- `deleteQuietly()` skips the event that increments the version, so the tag goes stale. `saveQuietly()` on a new item fails, because it assigns no rank key.
- Change an item's parent only with `move()` (ORD-08).
- `IsOrdered` runs `save()` for a new item and `delete()` in a transaction. A model that defines its own `save()` or `delete()` replaces that transaction.
- An ordered item does not support soft deletes. A trashed item keeps its rank key and still counts as a member.

## Connect the order to tags and preconditions

The package depends on no `aryeo/*` package except `aryeo/tooling-laravel`. The consumer connects the order to `api-resource-schema` and `api-first`.

**Item tags (ORD-39).** An item's tag must change whenever the version of its collection changes. Contribute that version to the item's schema:

```php
use Illuminate\Database\Eloquent\Model;
use Support\Http\Resources\Schemas\Tags\Contracts\Contributor;

/** @implements Contributor<Asset> */
class AssetOrderVersion implements Contributor
{
    public function contribute(Model $resource): string
    {
        return (string) $resource->orderedIn()->version();
    }
}
```

Declare it with `#[ContributesTag(AssetOrderVersion::class)]` on the `Asset` schema. A parent schema that opts in contributes `$resource->orderedCollection('assets')->version()` the same way.

**Collection actions (OCC-07, OCC-10).** A reorder or move action requires `If-Match` and compares it with the order tag. Give its `Precondition` a `Target` over `tag()`, and set `$required = true`:

```php
use Support\Http\Api\Preconditions\Contracts\Target;
use Support\Http\Resources\Schemas\Tags\Tag;
use Support\Ordering\OrderedCollection;

class OrderTarget implements Target
{
    public function __construct(private OrderedCollection $collection) {}

    public function tag(): Tag
    {
        return new Tag($this->collection->tag());
    }

    public function lock(): void
    {
        $this->collection->parent->newQuery()->whereKey($this->collection->parent->getKey())->lockForUpdate()->first();
    }
}
```

Run the change inside the precondition's `guard()`, and pass `$assets->version()` as the expected version. `lock()` holds the parent row, so that version is the one the recheck compared.

**Response tags (OCC-09, OCC-21).** The nested index returns the order tag:

```php
$tag = new Tag($assets->tag());
$members = $subsection->assets()->orderBy('rank_key')->get();

return $members->toSchemaCollection(ApiVersion::V2)->withETag($tag);
```

Read the tag before the members. A write between the two reads then fails the client's next `If-Match`, rather than matching an order the client never saw. A collection action passes `new Tag($change->tag)` to `withETag()`, because that tag matches the `order` it returns.

## Rank keys

`Support\Ordering\Keys\FractionalIndex` generates base-62 rank keys that sort in byte order (`strcmp`), never by locale.

```php
FractionalIndex::keyBetween(null, null);         // 'a0'
FractionalIndex::keyBetween('a0', 'a1');         // 'a0V'
FractionalIndex::keysBetween('a0', 'a1', 5);     // ['a08', 'a0G', 'a0V', 'a0d', 'a0l']
FractionalIndex::validate('a00');                // throws InvalidKey
```

A `null` bound means the start or the end of the collection. An invalid key throws `Support\Ordering\Keys\Exceptions\InvalidKey`.

## Credits

`FractionalIndex` is a PHP port of [rocicorp/fractional-indexing](https://github.com/rocicorp/fractional-indexing) 4.0.0, which is dedicated to the public domain under CC0-1.0. That library implements David Greenspan's [Implementing Fractional Indexing](https://observablehq.com/@dgreensp/implementing-fractional-indexing). The vectors in `tests/Fixtures/rocicorp-vectors.json` are that release's test cases for its default alphabet.
