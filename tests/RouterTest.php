<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tkmx\HfcmCli\Console\ExitCode;
use Tkmx\HfcmCli\Router;

class RouterTest extends TestCase
{
    public function testHelpFlagReturnsOk(): void
    {
        $router = new Router();
        ob_start();
        $code = $router->dispatch(['hfcm', '--help']);
        ob_end_clean();
        $this->assertSame(ExitCode::OK, $code);
    }

    public function testHelpCommandReturnsOk(): void
    {
        $router = new Router();
        ob_start();
        $code = $router->dispatch(['hfcm', 'help']);
        ob_end_clean();
        $this->assertSame(ExitCode::OK, $code);
    }

    public function testShortHelpFlagReturnsOk(): void
    {
        $router = new Router();
        ob_start();
        $code = $router->dispatch(['hfcm', '-h']);
        ob_end_clean();
        $this->assertSame(ExitCode::OK, $code);
    }

    public function testNoCommandPrintsHelp(): void
    {
        $router = new Router();
        ob_start();
        $code = $router->dispatch(['hfcm']);
        $output = ob_get_clean();
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString('Usage:', $output);
    }

    public function testUnknownCommandReturnsUsage(): void
    {
        $router = new Router();
        // Capture STDERR.
        $stderr = fopen('php://memory', 'r+');
        // We cannot easily redirect STDERR in unit test, but we can assert the exit code.
        $code = @$router->dispatch(['hfcm', 'snippets:nonexistent']);
        $this->assertSame(ExitCode::USAGE, $code);
    }

    public function testHelpOutputContainsCommands(): void
    {
        $router = new Router();
        ob_start();
        $router->dispatch(['hfcm', '--help']);
        $output = ob_get_clean();
        $this->assertStringContainsString('snippets:list', $output);
        $this->assertStringContainsString('snippets:bulk-upsert', $output);
        $this->assertStringContainsString('snippets:export', $output);
    }
}
