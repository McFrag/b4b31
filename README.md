# b4b31

A compact, read-only string database for PHP.

`b4b31` stores strings in binary files and retrieves them by numeric ID
using binary search. It is designed for applications that need fast
lookups with minimal memory usage.

## Features

-   Extremely low memory footprint
-   Fast `O(log n)` lookups
-   Read-only runtime (no locking required)
-   Supports sparse numeric IDs
-   Stores arbitrary binary or UTF-8 data
-   No database engine required
-   Pure PHP

## Design goals

-   Immutable at runtime.
-   Extremely small memory footprint.
-   Very simple binary format.
-   Fast lookup by integer ID.
-   No external dependencies.
-   Suitable for localization files and other large read-only string
    collections.

## Why?

Many PHP applications store thousands of localized strings or text
resources in arrays:

``` php
$strings = require 'lang.php';
echo $strings[1234];
```

While simple, this loads every string into memory even if only a few are
used.

`b4b31` instead keeps the data on disk and reads only the requested
string.

Runtime memory remains essentially constant regardless of the database
size.

## File formats

### Version 1

    database.dat
    database.idx

Each index record is:

    uint32 id
    uint32 offset
    uint32 length

Suitable for databases smaller than 4 GB.

### Version 2

    database.dat
    database.idx2

Each index record is:

    uint32 id
    uint64 offset
    uint32 length

Allows data files larger than 4 GB.

The runtime automatically prefers `.idx2` when present and falls back to
`.idx`.

## Building

``` sh
php build_string_files.php <directory> 1
```

creates `.idx`, while

``` sh
php build_string_files.php <directory> 2
```

creates `.idx2`.

The second parameter is mandatory.

Input files must be named:

    <hexadecimal-id>.txt

Example:

    00000001.txt
    0000000A.txt
    000000FF.txt

## Using

``` php
use b4b31\string_monger;

$db = new string_monger('database');

echo $db(0x15);
```

If both `.idx2` and `.idx` exist, `.idx2` is used automatically.

## Performance

Lookup complexity is `O(log n)`.

Only the requested string is read from disk. The entire string database
is never loaded into PHP memory.

## Why not SQLite?

SQLite is an excellent embedded database, but it stores data in B-tree
pages and includes a complete SQL engine. `b4b31` is considerably
simpler: it is a purpose-built immutable string store with a compact
index, no SQL parser, virtually no runtime memory overhead, and no
external dependencies.

If all you need is mapping integer IDs to strings, `b4b31` minimizes
complexity while remaining very fast. SQLite remains the better choice
whenever you need updates, transactions, indexing on multiple fields,
SQL queries, or concurrent writers.

## Repository layout

    b4b31_build/
        build_string_files.php

    inc/b4b31/
        string_monger.php

    examples/


## Tests

The test suite has no external dependencies:

```sh
php tests/run.php
```

GitHub Actions runs the syntax checks and test suite on PHP 7.4 through PHP 8.5.

## License

MIT License.
