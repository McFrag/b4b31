<?php
declare(strict_types=1);

/**
 * Builds a compiled string-lookup pair (<dirname>.idx / <dirname>.dat)
 * from a directory of files named <hexid>.txt, e.g. "1a2b3c.txt".
 * The content of each file (raw bytes, UTF-8 expected) becomes the
 * string associated with that id.
 *
 * Output files are written next to the source directory, named after
 * the directory's basename:
 *   <parent>/<dirname>.idx
 *   <parent>/<dirname>.dat
 *
 * Index record layout (12 bytes, big-endian):
 *   uint32 id
 *   uint32 position  (byte offset into .dat)
 *   uint32 size      (byte length of the string in .dat)
 *
 * Usage: php build_string_files.php /path/to/directory
 */

function fail(string $msg): void
{
    fwrite(STDERR, "Error: $msg\n");
    exit(1);
}

function pack_uint32_be(int $v): string
{
    return pack('N', $v & 0xFFFFFFFF);
}

if ($argc !== 2) {
    fail("Usage: php {$argv[0]} <directory>");
}

$dir = rtrim($argv[1], '/\\');

if (!is_dir($dir)) {
    fail("Not a directory: $dir");
}

// --- Collect records --------------------------------------------------

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
    if ($id < 0 || $id > 0xFFFFFFFF) {
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
        fwrite(STDERR, "Warning: duplicate id " . dechex($id) . " (file {$entry}) - overwriting previous entry\n");
    }

    $records[$id] = $content;
}
closedir($handle);

if (empty($records)) {
    fail("No valid <hexid>.txt files found in $dir");
}

// --- Sort by id (also determines physical layout of .dat, so ids that ---
// --- are numerically close end up physically close in the data file)  ---

ksort($records, SORT_NUMERIC);

// --- Output filenames ---------------------------------------------------

$dirname = basename($dir);
$parent  = dirname($dir);
$idxPath = $parent . DIRECTORY_SEPARATOR . $dirname . '.idx';
$datPath = $parent . DIRECTORY_SEPARATOR . $dirname . '.dat';

$idxTmp = $idxPath . '.tmp';
$datTmp = $datPath . '.tmp';

// --- Write files ---------------------------------------------------------

$datFh = fopen($datTmp, 'wb');
if ($datFh === false) {
    fail("Could not open $datTmp for writing");
}

$idxFh = fopen($idxTmp, 'wb');
if ($idxFh === false) {
    fail("Could not open $idxTmp for writing");
}

$position = 0;
$count = 0;

foreach ($records as $id => $content) {
    $size = strlen($content); // byte length, UTF-8 safe

    fwrite($datFh, $content);

    $record = pack_uint32_be($id)
            . pack_uint32_be($position)
            . pack_uint32_be($size);
    fwrite($idxFh, $record);

    $position += $size;
    $count++;
}

fclose($datFh);
fclose($idxFh);

// --- Atomic swap into place ----------------------------------------------
// rename() is atomic on POSIX filesystems, so readers never see a
// partially-written file, even if they're running concurrently.

if (!rename($datTmp, $datPath)) {
    fail("Could not move $datTmp to $datPath");
}
if (!rename($idxTmp, $idxPath)) {
    fail("Could not move $idxTmp to $idxPath");
}

echo "Built $count record(s):\n  $idxPath\n  $datPath\n";
