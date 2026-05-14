<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;
use Tkmx\HfcmCli\Support\PayloadException;
use Tkmx\HfcmCli\Support\PayloadLoader;

class PayloadLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/hfcm_cli_test_' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    public function testLoadFromInlineData(): void
    {
        $json = json_encode(['snippets' => [['name' => 'test']]]);
        $args = new Args(['--data=' . $json]);
        $result = PayloadLoader::load($args);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('snippets', $result);
    }

    public function testLoadFromPlainFile(): void
    {
        $path = $this->tmpDir . '/data.json';
        file_put_contents($path, json_encode(['snippets' => []]));
        $args = new Args(['--file=' . $path]);
        $result = PayloadLoader::load($args);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('snippets', $result);
    }

    public function testLoadFromGzFile(): void
    {
        $path = $this->tmpDir . '/data.json.gz';
        file_put_contents($path, gzencode(json_encode(['snippets' => [['name' => 'gz-test']]])));
        $args = new Args(['--file=' . $path]);
        $result = PayloadLoader::load($args);
        $this->assertIsArray($result);
        $this->assertSame('gz-test', $result['snippets'][0]['name']);
    }

    public function testInvalidJsonFromDataThrows(): void
    {
        $this->expectException(PayloadException::class);
        $this->expectExceptionCode(ExitCode::ERROR);
        $args = new Args(['--data=not-valid-json']);
        PayloadLoader::load($args);
    }

    public function testGzipMagicBytesDetected(): void
    {
        // Create a file without .gz extension but with gzip magic bytes.
        $path = $this->tmpDir . '/data.bin';
        file_put_contents($path, gzencode(json_encode(['key' => 'value'])));
        $args = new Args(['--file=' . $path]);
        $result = PayloadLoader::load($args);
        $this->assertSame('value', $result['key']);
    }

    public function testInvalidGzipThrows(): void
    {
        $path = $this->tmpDir . '/bad.json.gz';
        // Write gzip magic bytes followed by garbage to trigger gzdecode failure.
        file_put_contents($path, "\x1f\x8b" . str_repeat("\x00", 100));
        $this->expectException(PayloadException::class);
        $this->expectExceptionCode(ExitCode::ERROR);
        $args = new Args(['--file=' . $path]);
        PayloadLoader::load($args);
    }

    public function testFileNotFoundThrows(): void
    {
        $this->expectException(PayloadException::class);
        $args = new Args(['--file=/nonexistent/path/data.json']);
        PayloadLoader::load($args);
    }

    public function testNoSourceThrows(): void
    {
        $this->expectException(PayloadException::class);
        $this->expectExceptionCode(ExitCode::USAGE);
        $args = new Args([]);
        PayloadLoader::load($args);
    }

    public function testDataTakesPriorityOverFile(): void
    {
        // --data should be used even when --file is also present.
        $path = $this->tmpDir . '/other.json';
        file_put_contents($path, json_encode(['from' => 'file']));
        $json = json_encode(['from' => 'data']);
        $args = new Args(['--data=' . $json, '--file=' . $path]);
        $result = PayloadLoader::load($args);
        $this->assertSame('data', $result['from']);
    }

    public function testGzipWithoutExtensionDetectedByMagicBytes(): void
    {
        // A .bin file with gzip magic bytes should be decompressed transparently.
        $path = $this->tmpDir . '/payload.bin';
        file_put_contents($path, gzencode(json_encode(['source' => 'magic'])));
        $args   = new Args(['--file=' . $path]);
        $result = PayloadLoader::load($args);
        $this->assertSame('magic', $result['source']);
    }

    public function testGzFileWithValidContent(): void
    {
        // Redundant guard: .gz extension + valid content works end-to-end.
        $path = $this->tmpDir . '/data2.json.gz';
        file_put_contents($path, gzencode(json_encode(['ok' => true])));
        $args   = new Args(['--file=' . $path]);
        $result = PayloadLoader::load($args);
        $this->assertTrue($result['ok']);
    }

    public function testSymlinkIsRejected(): void
    {
        $realFile = $this->tmpDir . '/real.json';
        $linkFile = $this->tmpDir . '/link.json';
        file_put_contents($realFile, json_encode(['ok' => true]));
        symlink($realFile, $linkFile);

        $this->expectException(PayloadException::class);
        $args = new Args(['--file=' . $linkFile]);
        PayloadLoader::load($args);
    }

    public function testLargeFileIsAcceptedWithoutSizeLimit(): void
    {
        // DESIGN.md: no size limit for trusted-boundary CLI usage.
        // Write a file just over 10 MB to ensure no cap is applied.
        $path = $this->tmpDir . '/large.json';
        $bigString = str_repeat('x', 10 * 1024 * 1024 + 1);
        // Wrap in a valid JSON structure so decoding succeeds.
        $json = json_encode(['data' => $bigString]);
        file_put_contents($path, $json);

        $args   = new Args(['--file=' . $path]);
        $result = PayloadLoader::load($args);
        $this->assertArrayHasKey('data', $result);
    }
}
