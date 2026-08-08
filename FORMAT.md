# Binary File Format Specification

This document describes the on-disk format used by **b4b31**. The format
is designed to be simple, compact and easy to implement in any
programming language.

## Overview

A string database consists of:

-   One data file (`.dat`)
-   One index file (`.idx` or `.idx2`)

The `.dat` file contains the raw string data concatenated together with
no headers or separators.

The index file maps integer IDs to offsets inside the `.dat` file.

All integer values are stored in **big-endian (network) byte order**.

------------------------------------------------------------------------

# Version 1 (.idx)

Each index record occupies exactly **12 bytes**.

    Offset   Size Type     Description
  -------- ------ -------- ------------------------
         0      4 uint32   String ID
         4      4 uint32   Offset within `.dat`
         8      4 uint32   String length in bytes

Limits:

-   Maximum data file size: 4 GiB − 1 byte
-   Maximum string length: 4 GiB − 1 bytes

------------------------------------------------------------------------

# Version 2 (.idx2)

Each index record occupies exactly **16 bytes**.

    Offset   Size Type     Description
  -------- ------ -------- ------------------------
         0      4 uint32   String ID
         4      8 uint64   Offset within `.dat`
        12      4 uint32   String length in bytes

This format supports data files larger than 4 GiB.

------------------------------------------------------------------------

# Data file (.dat)

The data file is simply:

    [string0][string1][string2]...

Strings are stored exactly as supplied.

No encoding is imposed.

Binary data, UTF-8, UTF-16 or any other byte sequence is valid.

------------------------------------------------------------------------

# Index ordering

Records MUST be sorted in ascending order of the String ID.

The runtime performs a binary search on the index.

Duplicate IDs are not permitted.

------------------------------------------------------------------------

# Runtime selection

When opening a database, implementations should:

1.  Look for `<basename>.idx2`.
2.  If present, use it.
3.  Otherwise use `<basename>.idx`.

------------------------------------------------------------------------

# Error handling

Readers should reject databases if:

-   the index size is not an exact multiple of the record size
-   an offset points beyond the end of the data file
-   a string extends beyond the end of the data file
-   duplicate IDs are detected (optional but recommended)

Builders should reject:

-   duplicate IDs
-   integer values exceeding the selected format limits
-   incomplete writes

------------------------------------------------------------------------

# Compatibility

Version 2 changes only the width of the offset field.

The data file format is identical between versions.

Applications may safely rebuild an existing `.dat` using either index
format, provided the selected limits are respected.

Future versions should use new filename extensions rather than changing
the meaning of existing formats.
