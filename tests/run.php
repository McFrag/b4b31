<?php

declare(strict_types=1);

use b4b31\string_monger;

require_once __DIR__ . '/../inc/b4b31/string_monger.php';

final class TestFailure extends RuntimeException {}

$tests = 0;
$failures = 0;

function assert_true(bool $condition, string $message = 'assertion failed'): void
{
    if (!$condition) {
        throw new TestFailure($message);
    }
}

function assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $prefix = $message === '' ? '' : $message . ': ';
        throw new TestFailure(
            $prefix . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function test(string $name, callable $fn): void
{
    global $tests, $failures;
    $tests++;

    try {
        $fn();
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL $name\n";
        echo '     ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function temp_dir(): string
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'b4b31-test-' . bin2hex(random_bytes(8));
    if (!mkdir($base, 0700, true) && !is_dir($base)) {
        throw new RuntimeException("Could not create temporary directory: $base");
    }
    return $base;
}

function remove_tree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $items = scandir($path);
    if ($items !== false) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            remove_tree($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
}

/** @return array{0:int,1:string,2:string} */
function run_builder(string $directory, ?string $format): array
{
    $builder = realpath(__DIR__ . '/../b4b31_build/build_string_files.php');
    if ($builder === false) {
        throw new RuntimeException('Builder not found');
    }

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($builder) . ' ' . escapeshellarg($directory);
    if ($format !== null) {
        $cmd .= ' ' . escapeshellarg($format);
    }

    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open($cmd, $spec, $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start builder process');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    return [$exit, $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr];
}

function write_input_set(string $dir): void
{
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Could not create $dir");
    }

    file_put_contents($dir . '/0.txt', 'zero');
    file_put_contents($dir . '/1.txt', 'one');
    file_put_contents($dir . '/a.txt', 'δέκα');
    file_put_contents($dir . '/ff.txt', "binary\0value");
    file_put_contents($dir . '/100.txt', '');
}

function pack_u64_be(int $value): string
{
    if (PHP_INT_SIZE < 8) {
        throw new RuntimeException('64-bit PHP required by this test');
    }
    return pack('J', $value);
}

test('builder requires the format argument', function (): void {
    $tmp = temp_dir();
    try {
        $src = $tmp . '/strings';
        mkdir($src);
        file_put_contents($src . '/1.txt', 'one');
        [$exit, , $stderr] = run_builder($src, null);
        assert_true($exit !== 0, 'builder unexpectedly succeeded');
        assert_true(strpos($stderr, '<1|2>') !== false, 'usage error did not mention <1|2>');
    } finally {
        remove_tree($tmp);
    }
});

test('builder rejects an invalid format', function (): void {
    $tmp = temp_dir();
    try {
        $src = $tmp . '/strings';
        mkdir($src);
        file_put_contents($src . '/1.txt', 'one');
        [$exit, , $stderr] = run_builder($src, '3');
        assert_true($exit !== 0, 'builder unexpectedly succeeded');
        assert_true(strpos($stderr, 'exactly 1 or 2') !== false, 'wrong error message');
    } finally {
        remove_tree($tmp);
    }
});

test('builder rejects duplicate numeric ids', function (): void {
    $tmp = temp_dir();
    try {
        $src = $tmp . '/strings';
        mkdir($src);
        file_put_contents($src . '/a.txt', 'first');
        file_put_contents($src . '/0a.txt', 'second');
        [$exit, , $stderr] = run_builder($src, '1');
        assert_true($exit !== 0, 'builder unexpectedly accepted duplicates');
        assert_true(strpos($stderr, 'Duplicate id') !== false, 'duplicate error not reported');
    } finally {
        remove_tree($tmp);
    }
});

test('format 1 builds a 12-byte index and reads all supported data', function (): void {
    $tmp = temp_dir();
    try {
        $src = $tmp . '/strings';
        write_input_set($src);
        [$exit, $stdout, $stderr] = run_builder($src, '1');
        assert_same(0, $exit, $stderr);
        assert_true(strpos($stdout, 'index format 1') !== false, 'format not reported');

        $base = $tmp . '/strings';
        assert_true(is_file($base . '.idx'), '.idx was not created');
        assert_true(!is_file($base . '.idx2'), '.idx2 should not be created');
        assert_same(5 * 12, filesize($base . '.idx'), 'wrong .idx size');

        $m = new string_monger($base);
        assert_same('zero', $m(0));
        assert_same('one', $m(1));
        assert_same('δέκα', $m(0x0a));
        assert_same("binary\0value", $m(0xff));
        assert_same('', $m(0x100));
        assert_same(false, $m(0xdead));
    } finally {
        remove_tree($tmp);
    }
});

test('format 2 builds a 16-byte index and reads it', function (): void {
    if (PHP_INT_SIZE < 8) {
        echo "SKIP format 2 requires 64-bit PHP\n";
        return;
    }

    $tmp = temp_dir();
    try {
        $src = $tmp . '/strings';
        write_input_set($src);
        [$exit, $stdout, $stderr] = run_builder($src, '2');
        assert_same(0, $exit, $stderr);
        assert_true(strpos($stdout, 'index format 2') !== false, 'format not reported');

        $base = $tmp . '/strings';
        assert_true(is_file($base . '.idx2'), '.idx2 was not created');
        assert_same(5 * 16, filesize($base . '.idx2'), 'wrong .idx2 size');

        $m = new string_monger($base);
        assert_same('zero', $m(0));
        assert_same('δέκα', $m(0x0a));
        assert_same('', $m(0x100));
    } finally {
        remove_tree($tmp);
    }
});

test('monger prefers idx2 when both index files exist', function (): void {
    if (PHP_INT_SIZE < 8) {
        echo "SKIP format 2 requires 64-bit PHP\n";
        return;
    }

    $tmp = temp_dir();
    try {
        $base = $tmp . '/db';
        file_put_contents($base . '.dat', 'new-old');

        // idx says id 1 => "old"; idx2 says id 1 => "new".
        file_put_contents($base . '.idx', pack('N3', 1, 4, 3));
        file_put_contents($base . '.idx2', pack('N', 1) . pack_u64_be(0) . pack('N', 3));

        $m = new string_monger($base);
        assert_same('new', $m(1));
    } finally {
        remove_tree($tmp);
    }
});

test('monger falls back to idx when idx2 is absent', function (): void {
    $tmp = temp_dir();
    try {
        $base = $tmp . '/db';
        file_put_contents($base . '.dat', 'value');
        file_put_contents($base . '.idx', pack('N3', 7, 0, 5));
        $m = new string_monger($base);
        assert_same('value', $m(7));
    } finally {
        remove_tree($tmp);
    }
});

test('constructor rejects an index with an invalid byte length', function (): void {
    $tmp = temp_dir();
    try {
        $base = $tmp . '/db';
        file_put_contents($base . '.dat', 'x');
        file_put_contents($base . '.idx', str_repeat("\0", 11));

        $thrown = false;
        try {
            new string_monger($base);
        } catch (RuntimeException $e) {
            $thrown = true;
            assert_true(strpos($e->getMessage(), 'size not a multiple') !== false, 'unexpected exception');
        }
        assert_true($thrown, 'corrupt index was accepted');
    } finally {
        remove_tree($tmp);
    }
});

test('monger detects an index entry outside the data file', function (): void {
    $tmp = temp_dir();
    try {
        $base = $tmp . '/db';
        file_put_contents($base . '.dat', 'abc');
        file_put_contents($base . '.idx', pack('N3', 1, 2, 5));

        $reason = null;
        $m = new string_monger($base, function (int $id, string $idxPath, string $why) use (&$reason) {
            $reason = $why;
            return 'fallback';
        });

        assert_same('fallback', $m(1));
        assert_same('index points outside data file', $reason);
    } finally {
        remove_tree($tmp);
    }
});

test('not-found callback receives the requested id and index path', function (): void {
    $tmp = temp_dir();
    try {
        $base = $tmp . '/db';
        file_put_contents($base . '.dat', 'abc');
        file_put_contents($base . '.idx', pack('N3', 1, 0, 3));

        $seen = null;
        $m = new string_monger($base, function (int $id, string $idxPath, string $reason) use (&$seen) {
            $seen = [$id, $idxPath, $reason];
            return null;
        });

        assert_same(null, $m(99));
        assert_same([99, $base . '.idx', 'not_found'], $seen);
    } finally {
        remove_tree($tmp);
    }
});

echo "\n$tests test(s), $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
