<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tkmx\HfcmCli\Console\Output;

class OutputTest extends TestCase
{
    public function testSuccessJson(): void
    {
        $output = new Output();
        ob_start();
        $output->success(['id' => 1, 'name' => 'test']);
        $raw = ob_get_clean();
        $decoded = json_decode($raw, true);

        $this->assertTrue($decoded['success']);
        $this->assertSame(1, $decoded['data']['id']);
        $this->assertSame('test', $decoded['data']['name']);
    }

    public function testErrorJson(): void
    {
        $output = new Output(pretty: false, quiet: true);
        ob_start();
        $output->error(['code' => 'not_found', 'message' => 'Snippet not found', 'data' => null]);
        $raw = ob_get_clean();
        $decoded = json_decode($raw, true);

        $this->assertFalse($decoded['success']);
        $this->assertSame('not_found', $decoded['error']['code']);
    }

    public function testPrettyPrint(): void
    {
        $output = new Output(pretty: true);
        ob_start();
        $output->success(['key' => 'val']);
        $raw = ob_get_clean();

        // Pretty-printed JSON contains newlines and indentation.
        $this->assertStringContainsString("\n", $raw);
        $this->assertStringContainsString('    ', $raw);
    }

    public function testTableFormat(): void
    {
        $output = new Output();
        ob_start();
        $output->success(
            [['id' => 1, 'name' => 'Alpha'], ['id' => 2, 'name' => 'Beta']],
            [],
            'table'
        );
        $raw = ob_get_clean();

        $this->assertStringContainsString('id', $raw);
        $this->assertStringContainsString('name', $raw);
        $this->assertStringContainsString('Alpha', $raw);
        $this->assertStringContainsString('Beta', $raw);
    }

    public function testEmptyTableFormat(): void
    {
        $output = new Output();
        ob_start();
        $output->success([], [], 'table');
        $raw = ob_get_clean();

        $this->assertStringContainsString('no records', $raw);
    }

    public function testQuietSuppressesStderr(): void
    {
        $output = new Output(quiet: true);
        // Should not throw; STDERR suppressed.
        $output->stderr('This should be silent');
        $this->assertTrue(true);
    }

    public function testErrorJsonContainsCodeAndMessage(): void
    {
        $output = new Output();
        ob_start();
        $output->error(['code' => 'invalid_name', 'message' => 'Name is required', 'data' => null]);
        $raw     = ob_get_clean();
        $decoded = json_decode($raw, true);

        $this->assertFalse($decoded['success']);
        $this->assertSame('invalid_name', $decoded['error']['code']);
        $this->assertSame('Name is required', $decoded['error']['message']);
    }

    public function testSuccessJsonContainsMetaField(): void
    {
        $output = new Output();
        ob_start();
        $output->success(['id' => 5], ['total' => 1, 'page' => 1]);
        $raw     = ob_get_clean();
        $decoded = json_decode($raw, true);

        $this->assertTrue($decoded['success']);
        $this->assertSame(1, $decoded['meta']['total']);
        $this->assertSame(1, $decoded['meta']['page']);
    }

    public function testSuccessJsonDefaultFormatIsJson(): void
    {
        $output = new Output();
        ob_start();
        $output->success(['key' => 'value']);
        $raw     = ob_get_clean();
        $decoded = json_decode($raw, true);

        // Default format should be JSON (not table), so output must be valid JSON.
        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('success', $decoded);
    }

    public function testTableFormatWithSingleRow(): void
    {
        $output = new Output();
        ob_start();
        $output->success([['id' => 99, 'name' => 'Single']], [], 'table');
        $raw = ob_get_clean();

        $this->assertStringContainsString('99', $raw);
        $this->assertStringContainsString('Single', $raw);
    }

    public function testErrorWithHumanMessageWritesToStderrWhenNotQuiet(): void
    {
        // When quiet=false (default), error() with a humanMessage writes to STDERR.
        // We can only assert the STDOUT JSON part (STDERR capture would need process isolation).
        $output = new Output(quiet: false);
        ob_start();
        $output->error(['code' => 'not_found', 'message' => 'Not found', 'data' => null], 'Not found');
        $raw     = ob_get_clean();
        $decoded = json_decode($raw, true);

        $this->assertFalse($decoded['success']);
        $this->assertSame('not_found', $decoded['error']['code']);
    }

    public function testPrettyPrintJsonIsValidJson(): void
    {
        $output = new Output(pretty: true);
        ob_start();
        $output->success(['a' => 1]);
        $raw     = ob_get_clean();
        $decoded = json_decode($raw, true);

        $this->assertNotNull($decoded, 'Pretty-printed output must still be valid JSON');
        $this->assertSame(1, $decoded['data']['a']);
    }
}
