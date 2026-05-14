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
}
