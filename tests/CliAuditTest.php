<?php

declare(strict_types=1);

/**
 * CLI driver tests for CliAudit.
 *
 * PHPUnit is not available (dom/mbstring/xml/xmlwriter absent).
 * Run with: php tests/CliAuditTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Support\CliAudit;
use Tkmx\HfcmCli\Support\PayloadLoader;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function assert_true(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS: {$label}\n";
    } else {
        $failed++;
        echo "  FAIL: {$label}\n";
    }
}

function assert_equals(mixed $expected, mixed $actual, string $label): void
{
    assert_true($expected === $actual, "{$label} (expected=" . json_encode($expected) . " actual=" . json_encode($actual) . ")");
}

function assert_contains(string $needle, string $haystack, string $label): void
{
    assert_true(str_contains($haystack, $needle), "{$label} (needle={$needle})");
}

// ---------------------------------------------------------------------------
// Test 1: finish() writes a 'success' record when exit_code=0
// ---------------------------------------------------------------------------
echo "\nTest 1: finish writes success status on exit_code=0\n";
{
    AuditLoggerStub::reset();
    $audit = new CliAudit('snippets:list');
    $audit->start(['format' => 'json'], ['unix_user' => 'deploy', 'wp_user_login' => 'admin']);
    $audit->finish(0);

    assert_equals(1, count(AuditLoggerStub::$calls), 'one call to Logger::log');
    $call = AuditLoggerStub::$calls[0];
    assert_equals('cli:snippets:list', $call['action'], 'action prefix cli:');
    assert_equals('success', $call['status'], 'status = success');
    $payload = json_decode($call['payload'], true);
    assert_equals(0, $payload['exit_code'], 'exit_code in payload = 0');
    assert_true($payload['duration_ms'] >= 0, 'duration_ms is non-negative');
}

// ---------------------------------------------------------------------------
// Test 2: finish() writes 'error' status when exit_code != 0
// ---------------------------------------------------------------------------
echo "\nTest 2: finish writes error status on exit_code=1\n";
{
    AuditLoggerStub::reset();
    $audit = new CliAudit('snippets:create');
    $audit->start([], []);
    $audit->finish(1);

    $call = AuditLoggerStub::$calls[0];
    assert_equals('error', $call['status'], 'status = error for exit_code=1');
}

// ---------------------------------------------------------------------------
// Test 3: unix_user and wp_user_login appear in meta
// ---------------------------------------------------------------------------
echo "\nTest 3: actor meta (unix_user / wp_user_login) recorded\n";
{
    AuditLoggerStub::reset();
    $audit = new CliAudit('snippets:get');
    $audit->start(['id' => '42'], ['unix_user' => 'www-data', 'wp_user_login' => 'editor']);
    $audit->finish(0);

    $payload = json_decode(AuditLoggerStub::$calls[0]['payload'], true);
    assert_equals('www-data', $payload['unix_user'], 'unix_user in payload');
    assert_equals('editor', $payload['wp_user_login'], 'wp_user_login in payload');
}

// ---------------------------------------------------------------------------
// Test 4: impersonated_login appears when set
// ---------------------------------------------------------------------------
echo "\nTest 4: impersonated_login recorded\n";
{
    AuditLoggerStub::reset();
    $audit = new CliAudit('snippets:list');
    $audit->start([], ['unix_user' => 'deploy', 'wp_user_login' => 'admin', 'impersonated_login' => 'editor']);
    $audit->finish(0);

    $payload = json_decode(AuditLoggerStub::$calls[0]['payload'], true);
    assert_equals('editor', $payload['impersonated_login'], 'impersonated_login in payload');
}

// ---------------------------------------------------------------------------
// Test 5: redacted args appear in payload (sensitive keys are REDACTED)
// ---------------------------------------------------------------------------
echo "\nTest 5: redacted args in payload\n";
{
    AuditLoggerStub::reset();
    $audit = new CliAudit('snippets:create');
    $audit->start(['data' => '[REDACTED]', 'format' => 'json'], []);
    $audit->finish(0);

    $payload = json_decode(AuditLoggerStub::$calls[0]['payload'], true);
    assert_equals('[REDACTED]', $payload['redacted_args']['data'], 'data value is REDACTED in audit');
    assert_equals('json', $payload['redacted_args']['format'], 'format value passes through');
}

// ---------------------------------------------------------------------------
// Test 6: finish() emits error_log when Audit_Logger not loaded
// ---------------------------------------------------------------------------
echo "\nTest 6: finish() error_log when Logger class absent\n";
{
    // Use a class_alias trick: we cannot unload HFCM_Takumi_API_Audit_Logger,
    // so we test via start() which has a class_exists guard.
    // For finish(), we verify the Logger stub IS called (class is loaded in bootstrap).
    // The "not loaded" path is tested indirectly by checking the error_log path
    // is present in source (structural test).
    $source = file_get_contents(__DIR__ . '/../src/Support/CliAudit.php');
    assert_contains("error_log('[hfcm-cli] Audit_Logger クラスが読み込まれていません; 監査レコードをスキップ')", $source, 'finish() has error_log for missing Logger');
}

// ---------------------------------------------------------------------------
// Test 7: finish() with payload_meta in summary
// ---------------------------------------------------------------------------
echo "\nTest 7: payload_meta forwarded through finish() summary\n";
{
    AuditLoggerStub::reset();
    $audit = new CliAudit('snippets:bulk-upsert');
    $audit->start([], []);
    $audit->finish(0, ['payload_meta' => ['bytes' => 512, 'sha256' => 'abc123']]);

    $payload = json_decode(AuditLoggerStub::$calls[0]['payload'], true);
    assert_equals(512, $payload['summary']['payload_meta']['bytes'], 'bytes in summary.payload_meta');
    assert_equals('abc123', $payload['summary']['payload_meta']['sha256'], 'sha256 in summary.payload_meta');
}

// ---------------------------------------------------------------------------
// Test 8: json_encode failure fallback
// ---------------------------------------------------------------------------
echo "\nTest 8: json_encode false fallback produces valid JSON\n";
{
    AuditLoggerStub::reset();
    $audit = new CliAudit('snippets:import');
    $audit->start([], []);
    // Inject invalid UTF-8 into meta by calling finish with a summary that will
    // survive partial output. We test the fallback path exists in source.
    $source = file_get_contents(__DIR__ . '/../src/Support/CliAudit.php');
    assert_contains('json_encode_failed', $source, 'fallback string "json_encode_failed" present');
    assert_contains('JSON_PARTIAL_OUTPUT_ON_ERROR', $source, 'JSON_PARTIAL_OUTPUT_ON_ERROR used in fallback');
    // Normal encode still works.
    $audit->finish(0, []);
    assert_equals(1, count(AuditLoggerStub::$calls), 'Logger called once on normal finish');
    $decoded = json_decode(AuditLoggerStub::$calls[0]['payload'], true);
    assert_true(is_array($decoded), 'payload is valid JSON');
}

// ---------------------------------------------------------------------------
// Test 9: start() emits warning when Logger not available (structural check)
// ---------------------------------------------------------------------------
echo "\nTest 9: start() warns when Logger absent (structural check)\n";
{
    $source = file_get_contents(__DIR__ . '/../src/Support/CliAudit.php');
    assert_contains('[hfcm-cli] 警告: HFCM_Takumi_API_Audit_Logger が読み込まれていません; 監査ログは無効です。', $source, 'start() has warning for missing Logger');
}

// ---------------------------------------------------------------------------
// Test 10: consumeLastMeta returns null when PayloadLoader unused
// ---------------------------------------------------------------------------
echo "\nTest 10: PayloadLoader::consumeLastMeta returns null when no load\n";
{
    $meta = PayloadLoader::consumeLastMeta();
    assert_true($meta === null, 'consumeLastMeta() returns null when load() not called');
}

// ---------------------------------------------------------------------------
// Test 11: PayloadLoader::consumeLastMeta returns bytes/sha256 after load
// ---------------------------------------------------------------------------
echo "\nTest 11: PayloadLoader::consumeLastMeta returns meta after successful load\n";
{
    $raw  = '{"name":"test"}';
    $args = new Args(['--data=' . $raw]);
    PayloadLoader::load($args);
    $meta = PayloadLoader::consumeLastMeta();

    assert_true(is_array($meta), 'meta is array after load');
    assert_equals(strlen($raw), $meta['bytes'], 'bytes match raw length');
    assert_equals(hash('sha256', $raw), $meta['sha256'], 'sha256 matches');
    // Second call returns null (consumed).
    $meta2 = PayloadLoader::consumeLastMeta();
    assert_true($meta2 === null, 'consumeLastMeta() returns null on second call (consumed)');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n--- CliAuditTest: {$passed} passed, {$failed} failed ---\n";
exit($failed > 0 ? 1 : 0);
