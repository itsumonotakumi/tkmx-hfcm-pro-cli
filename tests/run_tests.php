<?php

declare(strict_types=1);

/**
 * Minimal test runner — no PHPUnit required.
 * Runs test classes whose test methods follow PHPUnit naming conventions.
 * Assertions: assertTrue, assertFalse (throws RuntimeException on failure).
 */

require __DIR__ . '/bootstrap.php';

// PHPUnit stub so test classes can extend TestCase without the library.
if (!class_exists('PHPUnit\Framework\TestCase')) {
    eval('
namespace PHPUnit\Framework;
class TestCase {
    public function __construct(string $name = "") {}
    protected function setUp(): void {}
    public static function assertTrue(mixed $v, string $m = ""): void {
        if (!(bool)$v) throw new \RuntimeException("assertTrue failed" . ($m ? ": $m" : ""));
    }
    public static function assertFalse(mixed $v, string $m = ""): void {
        if ((bool)$v) throw new \RuntimeException("assertFalse failed" . ($m ? ": $m" : ""));
    }
    public static function assertSame(mixed $e, mixed $a, string $m = ""): void {
        if ($e !== $a) throw new \RuntimeException("assertSame failed: expected " . var_export($e,true) . " got " . var_export($a,true) . ($m ? " $m" : ""));
    }
    public static function assertNull(mixed $v, string $m = ""): void {
        if ($v !== null) throw new \RuntimeException("assertNull failed: got " . var_export($v,true));
    }
    public static function assertNotNull(mixed $v, string $m = ""): void {
        if ($v === null) throw new \RuntimeException("assertNotNull failed");
    }
    public static function assertIsArray(mixed $v, string $m = ""): void {
        if (!is_array($v)) throw new \RuntimeException("assertIsArray failed");
    }
    public static function assertArrayHasKey(mixed $k, array $a, string $m = ""): void {
        if (!array_key_exists($k, $a)) throw new \RuntimeException("assertArrayHasKey failed: key $k missing");
    }
    public static function assertStringContainsString(string $needle, string $haystack, string $m = ""): void {
        if (strpos($haystack, $needle) === false) throw new \RuntimeException("assertStringContainsString failed: \"$needle\" not in \"$haystack\"");
    }
}
    ');
}


function run_test_file(string $file): array
{
    require_once $file;
    $class = basename($file, '.php');

    if (!class_exists($class)) {
        return [];
    }

    $results = [];
    $methods = array_filter(
        get_class_methods($class),
        fn(string $m) => str_starts_with($m, 'test')
    );

    foreach ($methods as $method) {
        $obj = new $class('runTest');
        if (method_exists($obj, 'setUp')) {
            $refSetUp = new \ReflectionMethod($obj, 'setUp');
            $refSetUp->setAccessible(true);
            $refSetUp->invoke($obj);
        }
        try {
            $refMethod = new \ReflectionMethod($obj, $method);
            $refMethod->setAccessible(true);
            $refMethod->invoke($obj);
            $results[$method] = 'PASS';
        } catch (\Throwable $e) {
            $results[$method] = 'FAIL: ' . $e->getMessage();
        }
    }

    return [$class => $results];
}

$testFiles = glob(__DIR__ . '/*Test.php');
sort($testFiles);

$total  = 0;
$passed = 0;
$failed = 0;

foreach ($testFiles as $file) {
    $classResults = run_test_file($file);
    foreach ($classResults as $class => $methods) {
        echo "\n$class\n";
        foreach ($methods as $method => $status) {
            $total++;
            if (str_starts_with($status, 'PASS')) {
                echo "  [OK]   $method\n";
                $passed++;
            } else {
                echo "  [FAIL] $method\n         $status\n";
                $failed++;
            }
        }
    }
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "Results: $passed passed, $failed failed, $total total\n";

exit($failed > 0 ? 1 : 0);
