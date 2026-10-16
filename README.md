# eloquent-ordering
Manually ordered Eloquent collections stored as fractional rank keys

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
