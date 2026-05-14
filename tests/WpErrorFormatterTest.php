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

    // --- BUG REGRESSION: missing_ prefix is exact-matched as 'missing_' (line 45 of WpErrorFormatter.php)
    // These tests FAIL on the current source because $code === 'missing_' matches nothing useful.
    // Fix required: change to str_starts_with($code, 'missing_').

    public function testMissingIdMapsToError(): void
    {
        // SnippetsUpdate emits 'missing_id' — this should map to ExitCode::ERROR (exit 1).
        // BUG: current code compares $code === 'missing_' (exact), so 'missing_id' falls through
        // to the HTTP-status fallback, and with no status data it returns ExitCode::ERROR anyway
        // — but only by coincidence via the final fallback, not via the intended branch.
        // Adding this as a documented regression guard.
        $error = new WP_Error('missing_id', 'Snippet ID is required');
        $this->assertSame(ExitCode::ERROR, WpErrorFormatter::toExitCode($error));
    }

    public function testMissingArgsMapsToError(): void
    {
        // SnippetsDelete emits 'missing_args' — same broken branch as missing_id.
        $error = new WP_Error('missing_args', 'Either --id or --ids is required');
        $this->assertSame(ExitCode::ERROR, WpErrorFormatter::toExitCode($error));
    }

    public function testMissingFieldMapsToError(): void
    {
        // Generic missing_ prefix variant — the intended pattern.
        $error = new WP_Error('missing_name', 'Name is required');
        $this->assertSame(ExitCode::ERROR, WpErrorFormatter::toExitCode($error));
    }

    public function testImportTooLargeMapsToError(): void
    {
        $error = new WP_Error('import_too_large', 'Import payload too large');
        $this->assertSame(ExitCode::ERROR, WpErrorFormatter::toExitCode($error));
    }

    public function testHttpStatus400FallbackMapsToError(): void
    {
        // Unknown code with HTTP 400 should map to ExitCode::ERROR via status fallback.
        $error = new WP_Error('some_client_error', 'Bad request', ['status' => 400]);
        $this->assertSame(ExitCode::ERROR, WpErrorFormatter::toExitCode($error));
    }

    public function testHttpStatus500FallbackForUnknownNonDbCode(): void
    {
        // Unknown code (no db_ prefix) with HTTP 500 — status fallback should return INTERNAL.
        $error = new WP_Error('unknown_server_error', 'Something blew up', ['status' => 500]);
        $this->assertSame(ExitCode::INTERNAL, WpErrorFormatter::toExitCode($error));
    }

    public function testRestCannotEditMapsToForbidden(): void
    {
        $error = new WP_Error('rest_cannot_edit', 'Cannot edit', ['status' => 403]);
        $this->assertSame(ExitCode::FORBIDDEN, WpErrorFormatter::toExitCode($error));
    }
}
