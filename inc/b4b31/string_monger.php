<?php

declare(strict_types=1);

namespace b4b31;

use RuntimeException;

/**
 * Reads strings by id from a compiled string data/index pair produced by
 * build_string_files.php.
 *
 * Index selection is automatic:
 *   1. <name>.idx2, if present (64-bit offsets)
 *   2. <name>.idx, otherwise (32-bit offsets)
 *
 * Usage:
 *   $monger = new string_monger('/path/to/mystrings'); // no extension
 *   $text = $monger->fetch(0x1a2b3c);
 *
 * With a callback for not-found / I/O errors:
 *   $monger = new string_monger('/path/to/mystrings', function (int $id, string $idxPath, string $reason) {
 *       return null;
 *   });
 */
class string_monger
{
    private const RECORD_SIZE_32 = 12; // 4 id + 4 position + 4 size
    private const RECORD_SIZE_64 = 16; // 4 id + 8 position + 4 size

    private string $idxPath;
    private string $datPath;
    private int $recordSize;
    private bool $offset64;
    private int $datSize;

    /** @var callable|null */
    private $notFoundCallback;

    /** @var resource|null */
    private $idxFh = null;

    /** @var resource|null */
    private $datFh = null;

    private int $recordCount;

    public function __construct(string $filename, ?callable $notFoundCallback = null)
    {
        $idx2Path = $filename . '.idx2';
        $idx1Path = $filename . '.idx';
        $this->datPath = $filename . '.dat';
        $this->notFoundCallback = $notFoundCallback;

        if (is_file($idx2Path)) {
            if (PHP_INT_SIZE < 8) {
                throw new RuntimeException('64-bit index files require a 64-bit PHP build');
            }
            $this->idxPath = $idx2Path;
            $this->recordSize = self::RECORD_SIZE_64;
            $this->offset64 = true;
        } elseif (is_file($idx1Path)) {
            $this->idxPath = $idx1Path;
            $this->recordSize = self::RECORD_SIZE_32;
            $this->offset64 = false;
        } else {
            throw new RuntimeException("Index file not found: neither {$idx2Path} nor {$idx1Path} exists");
        }

        if (!is_file($this->datPath)) {
            throw new RuntimeException("Data file not found: {$this->datPath}");
        }

        $idxSize = filesize($this->idxPath);
        if ($idxSize === false || $idxSize % $this->recordSize !== 0) {
            throw new RuntimeException(
                "Index file is corrupt (size not a multiple of {$this->recordSize}): {$this->idxPath}"
            );
        }

        $datSize = filesize($this->datPath);
        if ($datSize === false) {
            throw new RuntimeException("Could not determine data file size: {$this->datPath}");
        }

        $this->recordCount = intdiv($idxSize, $this->recordSize);
        $this->datSize = $datSize;
    }

    public function __destruct()
    {
        if ($this->idxFh !== null) {
            fclose($this->idxFh);
        }

        if ($this->datFh !== null) {
            fclose($this->datFh);
        }
    }

    /**
     * Fetch the string associated with $id.
     *
     * On success: returns the string.
     * On failure (id not found, corrupt index/data, or I/O error): calls the
     * callback as ($id, $idxPath, $reason) and returns whatever it returns,
     * or false if no callback was given.
     *
     * @return string|mixed|false
     */
    public function fetch(int $id)
    {
        try {
            $slot = $this->findRecord($id);
            if ($slot === null) {
                return $this->fail($id, 'not_found');
            }

            [, $position, $size] = $this->readRecord($slot);

            if ($position < 0 || $size < 0 || $position > $this->datSize || $size > $this->datSize - $position) {
                return $this->fail($id, 'index points outside data file');
            }

            if ($size === 0) {
                return '';
            }

            $this->ensureDatOpen();
            if (fseek($this->datFh, $position) !== 0) {
                return $this->fail($id, "seek failed at offset {$position}");
            }

            $data = $this->readExact($this->datFh, $size);
            if ($data === false) {
                return $this->fail($id, 'short read');
            }

            return $data;
        } catch (RuntimeException $e) {
            return $this->fail($id, $e->getMessage());
        }
    }

    /** Routes a failure through the callback (if any), else returns false. */
    private function fail(int $id, string $reason)
    {
        return $this->notFoundCallback !== null
            ? ($this->notFoundCallback)($id, $this->idxPath, $reason)
            : false;
    }

    public function __invoke(int $id)
    {
        return $this->fetch($id);
    }

    /** Binary search the index (sorted by id) for the record slot matching $id. */
    private function findRecord(int $id): ?int
    {
        $lo = 0;
        $hi = $this->recordCount - 1;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            [$recId] = $this->readRecord($mid);

            if ($recId === $id) {
                return $mid;
            }

            if ($recId < $id) {
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return null;
    }

    /** @return array{0:int,1:int,2:int} [id, position, size] */
    private function readRecord(int $slot): array
    {
        $this->ensureIdxOpen();

        $offset = $slot * $this->recordSize;
        if (fseek($this->idxFh, $offset) !== 0) {
            throw new RuntimeException("Seek failed in {$this->idxPath} at offset {$offset}");
        }

        $raw = $this->readExact($this->idxFh, $this->recordSize);
        if ($raw === false) {
            throw new RuntimeException("Short read in {$this->idxPath} at record {$slot}");
        }

        if ($this->offset64) {
            $vals = unpack('Nid/Jpos/Nsize', $raw);
        } else {
            $vals = unpack('Nid/Npos/Nsize', $raw);
        }

        if ($vals === false) {
            throw new RuntimeException("Could not decode record {$slot} in {$this->idxPath}");
        }

        return [(int)$vals['id'], (int)$vals['pos'], (int)$vals['size']];
    }

    /** @param resource $fh */
    private function readExact($fh, int $length)
    {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($fh, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                return false;
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function ensureIdxOpen(): void
    {
        if ($this->idxFh === null) {
            $fh = fopen($this->idxPath, 'rb');
            if ($fh === false) {
                throw new RuntimeException("Could not open {$this->idxPath}");
            }
            $this->idxFh = $fh;
        }
    }

    private function ensureDatOpen(): void
    {
        if ($this->datFh === null) {
            $fh = fopen($this->datPath, 'rb');
            if ($fh === false) {
                throw new RuntimeException("Could not open {$this->datPath}");
            }
            $this->datFh = $fh;
        }
    }
}
