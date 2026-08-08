<?php
declare(strict_types=1);
/**
 * Reads strings by id from a compiled <name>.idx / <name>.dat pair,
 * as produced by build_string_files.php.
 *
 * Usage:
 *   $monger = new string_monger('/path/to/mystrings'); // no extension
 *   $text = $monger->fetch(0x1a2b3c);
 *
 *   // With a callback for not-found / I/O errors:
 *   $monger = new string_monger('/path/to/mystrings', function (int $id, string $idxPath, string $reason) {
 *       // $reason is 'not_found' or a short description of an I/O failure
 *       return null;
 *   });
 */
class string_monger
{
    private const RECORD_SIZE = 12; // 4 (id) + 4 (position) + 4 (size), big-endian
    private string $idxPath;
    private string $datPath;
    /** @var callable|null */
    private $notFoundCallback;
    /** @var resource|null */
    private $idxFh = null;
    /** @var resource|null */
    private $datFh = null;
    private int $recordCount;
    public function __construct(string $filename, ?callable $notFoundCallback = null)
    {
        $this->idxPath = $filename . '.idx';
        $this->datPath = $filename . '.dat';
        $this->notFoundCallback = $notFoundCallback;
        if (!is_file($this->idxPath)) {
            throw new RuntimeException("Index file not found: {$this->idxPath}");
        }
        if (!is_file($this->datPath)) {
            throw new RuntimeException("Data file not found: {$this->datPath}");
        }
        $idxSize = filesize($this->idxPath);
        if ($idxSize === false || $idxSize % self::RECORD_SIZE !== 0) {
            throw new RuntimeException(
                "Index file is corrupt (size not a multiple of " . self::RECORD_SIZE . "): {$this->idxPath}"
            );
        }
        $this->recordCount = intdiv($idxSize, self::RECORD_SIZE);
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
     * On failure (id not found, or an I/O error while reading it): calls
     * the callback as ($id, $idxPath, $reason) and returns whatever it
     * returns, or false if no callback was given. $reason is 'not_found'
     * or a short description of the I/O failure.
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
            if ($size === 0) {
                return '';
            }

            $this->ensureDatOpen();
            if (fseek($this->datFh, $position) !== 0) {
                return $this->fail($id, "seek failed at offset {$position}");
            }

            $data = fread($this->datFh, $size);
            if ($data === false || strlen($data) !== $size) {
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

    public function __invoke($id)
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
    /** @return array{0:int,1:int,2:int} [id, position, size] for the given slot */
    private function readRecord(int $slot): array
    {
        $this->ensureIdxOpen();
        $offset = $slot * self::RECORD_SIZE;
        if (fseek($this->idxFh, $offset) !== 0) {
            throw new RuntimeException("Seek failed in {$this->idxPath} at offset {$offset}");
        }
        $raw = fread($this->idxFh, self::RECORD_SIZE);
        if ($raw === false || strlen($raw) !== self::RECORD_SIZE) {
            throw new RuntimeException("Short read in {$this->idxPath} at record {$slot}");
        }
        $vals = unpack('Nid/Npos/Nsize', $raw);
        return [$vals['id'], $vals['pos'], $vals['size']];
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
