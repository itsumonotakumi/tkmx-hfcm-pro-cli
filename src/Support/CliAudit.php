<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Support;

use Tkmx\HfcmCli\Console\Args;

/**
 * Wraps HFCM_Takumi_API_Audit_Logger::log() for CLI invocations.
 * Records actor (UNIX user + WP user_login), command, redacted args, result.
 *
 * Only one real DB record is written: at finish() time (success or error).
 * This keeps audit logs clean — no spurious 500-status "start" entries.
 */
class CliAudit
{
    private string $command;
    private float $startedAt;
    /** @var array<string, mixed> */
    private array $meta;

    public function __construct(string $command)
    {
        $this->command   = $command;
        $this->startedAt = microtime(true);
        $this->meta      = [];
    }

    /**
     * Record command start context (stored in memory; not written to DB).
     *
     * @param array<string, mixed> $redactedArgs
     * @param array<string, mixed> $extra  e.g. ['actor' => ..., 'impersonated_login' => ...]
     */
    public function start(array $redactedArgs, array $extra = []): void
    {
        // Collect UNIX user and WP user_login.
        $unixUser = get_current_user() ?: (function_exists('posix_getlogin') ? posix_getlogin() : '');
        $wpLogin  = '';
        if (function_exists('wp_get_current_user')) {
            $wpUser  = wp_get_current_user();
            $wpLogin = $wpUser->user_login ?? '';
        }

        $this->meta = array_merge([
            'unix_user'    => $unixUser,
            'wp_user_login' => $wpLogin,
            'redacted_args' => $redactedArgs,
        ], $extra);

        // No DB write here — avoids 'info' status causing 500-coded audit rows.
        // The audit record is written once at finish().
        if (!class_exists('HFCM_Takumi_API_Audit_Logger')) {
            error_log('[hfcm-cli] Warning: HFCM_Takumi_API_Audit_Logger not loaded; audit logging is disabled.');
        }
    }

    /**
     * Write the single audit record for this command invocation.
     *
     * @param array<string, mixed> $summary
     */
    public function finish(int $exitCode, array $summary = []): void
    {
        if (!class_exists('HFCM_Takumi_API_Audit_Logger')) {
            error_log('[hfcm-cli] Audit_Logger class not loaded; audit record skipped');
            return;
        }

        $durationMs = (int) round((microtime(true) - $this->startedAt) * 1000);
        $status     = $exitCode === 0 ? 'success' : 'error';

        $payload = array_merge($this->meta, [
            'exit_code'   => $exitCode,
            'duration_ms' => $durationMs,
            'summary'     => $summary,
        ]);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Fallback: encode with partial output on error to avoid passing false to Logger.
            $json = json_encode(
                array_merge($this->meta, [
                    'exit_code'    => $exitCode,
                    'duration_ms'  => $durationMs,
                    'encode_error' => json_last_error_msg(),
                ]),
                JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            ) ?: '{"error":"json_encode_failed"}';
        }

        \HFCM_Takumi_API_Audit_Logger::log(
            'cli:' . $this->command,
            $status,
            $json
        );
    }
}
