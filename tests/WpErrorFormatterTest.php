<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tkmx\HfcmCli\Support\WpErrorFormatter;
use Tkmx\HfcmCli\Console\ExitCode;

class WpErrorFormatterTest extends TestCase
{
    public function testToArray(): void
    {
        $error = new WP_Error('not_found', 'Snippet not found', ['status' => 404]);
        $arr   = WpErrorFormatter::toArray($error);

        $this->assertSame('not_found', $arr['code']);
        $this->assertSame('Snippet not found', $arr['message']);
        $this->assertSame(['status' => 404], $arr['data']);
    }

    public function testNotFoundMapsToError(): void
    {
        $error = new WP_Error('not_found', 'Not found', ['status' => 404]);
        $this->assertSame(ExitCode::ERROR, WpErrorFormatter::toExitCode($error));
    }

    public function testInvalidPrefixMapsToError(): void
    {
        $error = new WP_Error('invalid_name', 'Name is required');
        $this->assertSame(ExitCode::ERROR, WpErrorFormatter::toExitCode($error));
    }

    public function testPayloadTooLargeMapsToError(): void
    {
        $error = new WP_Error('payload_too_large', 'Too large');
        $this->assertSame(ExitCode::ERROR, WpErrorFormatter::toExitCode($error));
    }

    public function testForbiddenMapsToForbidden(): void
    {
        $error = new WP_Error('rest_forbidden', 'Forbidden', ['status' => 403]);
        $this->assertSame(ExitCode::FORBIDDEN, WpErrorFormatter::toExitCode($error));
    }

    public function testRestNotLoggedInMapsToForbidden(): void
    {
        $error = new WP_Error('rest_not_logged_in', 'Not authenticated', ['status' => 401]);
        $this->assertSame(ExitCode::FORBIDDEN, WpErrorFormatter::toExitCode($error));
    }

    public function testHttpStatus403FallbackMapsToForbidden(): void
    {
        $error = new WP_Error('some_custom_code', 'Denied', ['status' => 403]);
        $this->assertSame(ExitCode::FORBIDDEN, WpErrorFormatter::toExitCode($error));
    }

    public function testHttpStatus500FallbackMapsToInternal(): void
    {
        $error = new WP_Error('some_server_error', 'Server error', ['status' => 500]);
        $this->assertSame(ExitCode::INTERNAL, WpErrorFormatter::toExitCode($error));
    }

    public function testDbErrorMapsToInternal(): void
    {
        $error = new WP_Error('db_insert_error', 'DB failed');
        $this->assertSame(ExitCode::INTERNAL, WpErrorFormatter::toExitCode($error));
    }

    public function testBulkPrefixMapsToError(): void
    {
        $error = new WP_Error('bulk_upsert_too_large', 'Too many items');
        $this->assertSame(ExitCode::ERROR, WpErrorFormatter::toExitCode($error));
    }

    public function testUnknownCodeWithNoStatusFallsBackToError(): void
    {
        $error = new WP_Error('some_unknown_code', 'Something went wrong');
        $this->assertSame(ExitCode::ERROR, WpErrorFormatter::toExitCode($error));
    }
}
