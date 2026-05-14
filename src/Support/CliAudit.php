<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Support;

use Tkmx\HfcmCli\Console\Args;

/**
 * Wraps HFCM_Takumi_API_Audit_Logger::log() for CLI invocations.
 * Records actor (UNIX user + WP user_login), command, redacted args, result.
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
     * Log start of command execution.
     * @param array<string, mixed> $redactedArgs
     * @param array<string, mixed> $extra  e.g. ['actor' => ..., 'impersonated' => ...]
     */
    public function start(array $redactedArgs, array $extra = []): void
    {
        $this->meta = array_merge([
            'unix_user'      => get_current_user() ?: posix_getlogin(),
            'redacted_args'  => $redactedArgs,
        ], $extra);

        if (!class_exists('HFCM_Takumi_API_Audit_Logger')) {
            return;
        }

        \HFCM_Takumi_API_Audit_Logger::log(
            'cli:' . $this->command,
            'info',
            json_encode(array_merge(['event' => 'start'], $this->meta), JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Log finish of command execution.
     * @param array<string, mixed> $summary
     */
    public function finish(int $exitCode, array $summary = []): void
    {
        if (!class_exists('HFCM_Takumi_API_Audit_Logger')) {
            return;
        }

        $durationMs = (int) round((microtime(true) - $this->startedAt) * 1000);
        $status     = $exitCode === 0 ? 'success' : 'error';

        $payload = array_merge($this->meta, [
            'event'       => 'finish',
            'exit_code'   => $exitCode,
            'duration_ms' => $durationMs,
            'summary'     => $summary,
        ]);

        \HFCM_Takumi_API_Audit_Logger::log(
            'cli:' . $this->command,
            $status,
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
    }
}
