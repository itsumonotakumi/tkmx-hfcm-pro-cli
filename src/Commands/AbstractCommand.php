<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Commands;

use Tkmx\HfcmCli\Bootstrap;
use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;
use Tkmx\HfcmCli\Console\Output;
use Tkmx\HfcmCli\Support\CliAudit;
use Tkmx\HfcmCli\Support\ExecutionLock;
use Tkmx\HfcmCli\Support\PayloadException;
use Tkmx\HfcmCli\Support\WpErrorFormatter;

abstract class AbstractCommand
{
    protected Output $output;

    /** Override to true for write commands that need the exclusive lock. */
    protected bool $requiresLock = false;

    /**
     * Minimum WP capability required.
     * Override in subclasses for read-only commands (e.g. 'read').
     * Bootstrap no longer enforces manage_options globally.
     */
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

        // Build extra audit context: unix_user, wp_user_login, impersonated_login.
        $actorContext = Bootstrap::getActorContext() ?? [];
        $extra = array_filter([
            'unix_user'          => $actorContext['unix_user'] ?? null,
            'wp_user_login'      => $actorContext['wp_user_login'] ?? null,
            'impersonated_login' => $actorContext['impersonated_login'] ?? null,
        ], fn($v) => $v !== null);

        $audit->start($args->toRedactedArray(), $extra);

        // Per-command capability guard (replaces Bootstrap-level manage_options).
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
        } catch (PayloadException $e) {
            // PayloadException carries its own exit code (ERROR or USAGE).
            // Must be caught before \Throwable to avoid being swallowed as INTERNAL.
            $this->output->error(
                ['code' => 'payload_error', 'message' => $e->getMessage()],
                $e->getMessage()
            );
            $exitCode = $e->getExitCode();
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
