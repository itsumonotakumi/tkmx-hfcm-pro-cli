<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Commands;

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;
use Tkmx\HfcmCli\Console\Output;
use Tkmx\HfcmCli\Support\CliAudit;
use Tkmx\HfcmCli\Support\ExecutionLock;
use Tkmx\HfcmCli\Support\WpErrorFormatter;

abstract class AbstractCommand
{
    protected Output $output;

    /** Override to true for write commands that need the exclusive lock. */
    protected bool $requiresLock = false;

    /** Minimum WP capability required. Override for read-only commands. */
    protected string $requiredCap = 'manage_options';

    abstract protected function commandName(): string;

    abstract protected function execute(Args $args): int;

    public function __construct(Args $args)
    {
        $this->output = new Output(
            pretty: $args->getBool('pretty'),
            quiet: $args->getBool('quiet'),
        );
    }

    public function run(Args $args): int
    {
        $audit = new CliAudit($this->commandName());
        $audit->start($args->toRedactedArray());

        // Capability guard.
        if (!function_exists('current_user_can') || !current_user_can($this->requiredCap)) {
            $this->output->error(
                ['code' => 'rest_forbidden', 'message' => 'Insufficient permissions'],
                'Insufficient permissions (requires: ' . $this->requiredCap . ')'
            );
            $audit->finish(ExitCode::FORBIDDEN);
            return ExitCode::FORBIDDEN;
        }

        $locked = false;
        if ($this->requiresLock) {
            if (!ExecutionLock::acquire()) {
                $this->output->error(
                    ['code' => 'import_in_progress', 'message' => 'Another import/upsert is already running'],
                    'Another import/upsert is already running. Please try again later.'
                );
                $audit->finish(ExitCode::TEMPFAIL);
                return ExitCode::TEMPFAIL;
            }
            $locked = true;
        }

        $exitCode = ExitCode::INTERNAL;
        try {
            $exitCode = $this->execute($args);
        } catch (\Throwable $e) {
            $this->output->error(
                ['code' => 'internal_error', 'message' => $e->getMessage()],
                $e->getMessage()
            );
            $exitCode = ExitCode::INTERNAL;
        } finally {
            if ($locked) {
                ExecutionLock::release();
            }
        }

        $audit->finish($exitCode);
        return $exitCode;
    }

    /**
     * Handle a WP_Error: write JSON error output and return the mapped exit code.
     */
    protected function handleWpError(\WP_Error $error): int
    {
        $arr      = WpErrorFormatter::toArray($error);
        $exitCode = WpErrorFormatter::toExitCode($error);
        $this->output->error($arr, $arr['message']);
        return $exitCode;
    }
}
