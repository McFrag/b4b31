<?php

declare(strict_types=1);

/**
 * Builds a compiled string-lookup pair from a directory of files named
 * <hexid>.txt, e.g. "1a2b3c.txt". The content of each file (raw bytes,
 * UTF-8 expected) becomes the string associated with that id.
 *
 * The second argument selects the index format and is mandatory:
 *
 *   1: <dirname>.idx
 *      12-byte records, big-endian:
 *        uint32 id
 *        uint32 position
 *        uint32 size
 *
 *   2: <dirname>.idx2
 *      16-byte records, big-endian:
 *        uint32 id
 *        uint64 position
 *        uint32 size
 *
 * The data file is always <dirname>.dat.
 *
 * Usage:
 *   php build_string_files.php /path/to/directory 1
 *   php build_string_files.php /path/to/directory 2
 */

function fail(string $msg): void
{
    fwrite(STDERR, "Error: $msg\n");
    exit(1);
}

function pack_uint32_be(int $v): string
{
    if ($v < 0 || $v > 0xFFFFFFFF) {
        fail("Value out of uint32 range: $v");
    }

    return pack('N', $v);
}

function pack_uint64_be(int $v): string
{
    if ($v < 0) {
        fail("Value out of uint64 range: $v");
    }

    // PHP integers are signed. On a 64-bit build this supports offsets up to
    // PHP_INT_MAX, which is also the practical limit for fseek()/filesize().
    if (PHP_INT_SIZE < 8) {
        fail('Index format 2 requires a 64-bit PHP build');
    }

    return pack('J', $v);
}

function write_all($fh, string $data, string $path): void
{
    $length = strlen($data);
    $written = 0;

    while ($written < $length) {
        $n = fwrite($fh, substr($data, $written));
        if ($n === false || $n === 0) {
            fail("Write failed: $path");
        }
        $written += $n;
    }
}

if ($argc !== 3) {
    fail("Usage: php {$argv[0]} <directory> <1|2>");
}

$dir = rtrim($argv[1], '/\\');
$format = filter_var($argv[2], FILTER_VALIDATE_INT);

if ($format !== 1 && $format !== 2) {
    fail('Index format must be exactly 1 or 2');
}

if ($format === 2 && PHP_INT_SIZE < 8) {
    fail('Index format 2 requires a 64-bit PHP build');
}

if (!is_dir($dir)) {
    fail("Not a directory: $dir");
}

// --- Collect records ------------------------------------------------------
$records = []; // id => string content (raw bytes)
$handle = opendir($dir);

if ($handle === false) {
    fail("Could not open directory: $dir");
}

while (($entry = readdir($handle)) !== false) {
    if (!preg_match('/^([0-9a-fA-F]+)\.txt$/', $entry, $m)) {
        continue;
    }

    $id = hexdec($m[1]);
    if (!is_int($id) || $id < 0 || $id > 0xFFFFFFFF) {
        fwrite(STDERR, "Skipping {$entry}: id out of 32-bit range\n");
        continue;
    }

    $path = $dir . DIRECTORY_SEPARATOR . $entry;
    $content = file_get_contents($path);

    if ($content === false) {
        fwrite(STDERR, "Skipping {$entry}: could not read file\n");
        continue;
    }

    if (isset($records[$id])) {
        fwrite(
            STDERR,
            'Warning: duplicate id ' . dechex($id) . " (file {$entry}) - overwriting previous entry\n"
        );
    }

    $records[$id] = $content;
}

closedir($handle);

if (empty($records)) {
    fail("No valid <hexid>.txt files found in $dir");
}

// --- Sort by id (also determines physical layout of .dat) ----------------
ksort($records, SORT_NUMERIC);

// --- Output filenames -----------------------------------------------------
$dirname = basename($dir);
$parent = dirname($dir);
$idxExtension = $format === 2 ? '.idx2' : '.idx';
$idxPath = $parent . DIRECTORY_SEPARATOR . $dirname . $idxExtension;
$datPath = $parent . DIRECTORY_SEPARATOR . $dirname . '.dat';
$idxTmp = $idxPath . '.tmp';
$datTmp = $datPath . '.tmp';

// --- Write files ----------------------------------------------------------
$datFh = fopen($datTmp, 'wb');
if ($datFh === false) {
    fail("Could not open $datTmp for writing");
}

$idxFh = fopen($idxTmp, 'wb');
if ($idxFh === false) {
    fclose($datFh);
    @unlink($datTmp);
    fail("Could not open $idxTmp for writing");
}

$position = 0;
$count = 0;

foreach ($records as $id => $content) {
    $size = strlen($content); // byte length, UTF-8 safe

    if ($size > 0xFFFFFFFF) {
        fclose($datFh);
        fclose($idxFh);
        @unlink($datTmp);
        @unlink($idxTmp);
        fail('String ' . dechex($id) . ' exceeds the uint32 size limit');
    }

    if ($format === 1 && $position > 0xFFFFFFFF) {
        fclose($datFh);
        fclose($idxFh);
        @unlink($datTmp);
        @unlink($idxTmp);
        fail('Data offset exceeds the uint32 limit; rebuild using index format 2');
    }

    if ($format === 1 && $size > 0 && $position + $size - 1 > 0xFFFFFFFF) {
        fclose($datFh);
        fclose($idxFh);
        @unlink($datTmp);
        @unlink($idxTmp);
        fail('Data file exceeds the addressable range of index format 1; rebuild using format 2');
    }

    write_all($datFh, $content, $datTmp);

    $record = pack_uint32_be($id)
        . ($format === 2 ? pack_uint64_be($position) : pack_uint32_be($position))
        . pack_uint32_be($size);

    write_all($idxFh, $record, $idxTmp);

    $position += $size;
    $count++;
}

if (!fclose($datFh)) {
    fclose($idxFh);
    @unlink($datTmp);
    @unlink($idxTmp);
    fail("Could not close $datTmp cleanly");
}

if (!fclose($idxFh)) {
    @unlink($datTmp);
    @unlink($idxTmp);
    fail("Could not close $idxTmp cleanly");
}

// --- Swap into place ------------------------------------------------------
// Builds are expected to run while the application is offline. Each rename
// still prevents either individual file from being observed partially written.
if (!rename($datTmp, $datPath)) {
    @unlink($idxTmp);
    fail("Could not move $datTmp to $datPath");
}

if (!rename($idxTmp, $idxPath)) {
    fail("Could not move $idxTmp to $idxPath");
}

echo "Built $count record(s), index format $format:\n $idxPath\n $datPath\n";
