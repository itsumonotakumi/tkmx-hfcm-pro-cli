<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tkmx\HfcmCli\Commands\AbstractCommand;
use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;
use Tkmx\HfcmCli\Support\PayloadException;

/**
 * Minimal concrete subclass for testing AbstractCommand behaviour.
 */
class ConcreteCommand extends AbstractCommand
{
    public string $forcedCap = 'manage_options';
    public int $executeReturn = ExitCode::OK;
    public bool $lock = false;

    public function __construct(Args $args)
    {
        parent::__construct($args);
        $this->requiredCap  = $this->forcedCap;
        $this->requiresLock = $this->lock;
    }

    protected function commandName(): string
    {
        return 'test:command';
    }

    protected function execute(Args $args): int
    {
        return $this->executeReturn;
    }
}

class AbstractCommandTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset transient/option store (ExecutionLock depends on it).
        TransientStore::reset();
        // Reset ExecutionLock owner token.
        $ref  = new ReflectionClass(\Tkmx\HfcmCli\Support\ExecutionLock::class);
        $prop = $ref->getProperty('ownerToken');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
        // Default: user has capability.
        $GLOBALS['_hfcm_current_user_can'] = true;
    }

    public function testRunReturnsOkWhenExecuteSucceeds(): void
    {
        $args = new Args([]);
        $cmd  = new ConcreteCommand($args);
        $code = $cmd->run($args);
        $this->assertSame(ExitCode::OK, $code);
    }

    public function testRunReturnsForbiddenWhenCapCheckFails(): void
    {
        $GLOBALS['_hfcm_current_user_can'] = false;
        $args = new Args([]);
        $cmd  = new ConcreteCommand($args);

        // Capture output (error() writes JSON to STDOUT or STDERR via Output).
        ob_start();
        $code = $cmd->run($args);
        $out  = ob_get_clean();

        $this->assertSame(ExitCode::FORBIDDEN, $code);
    }

    public function testRunReturnsTempfailWhenLockNotAcquired(): void
    {
        // Pre-occupy the lock so acquire() returns false.
        set_transient('hfcm_import_lock', time(), 300);

        $args = new Args([]);
        $cmd  = new ConcreteCommand($args);
        // Enable lock requirement.
        $ref  = new ReflectionClass(ConcreteCommand::class);
        $prop = $ref->getProperty('requiresLock');
        $prop->setAccessible(true);
        $prop->setValue($cmd, true);

        ob_start();
        $code = $cmd->run($args);
        ob_end_clean();

        $this->assertSame(ExitCode::TEMPFAIL, $code);
    }

    public function testRunReleasesLockAfterExecute(): void
    {
        $args = new Args([]);
        $cmd  = new ConcreteCommand($args);
        $ref  = new ReflectionClass(ConcreteCommand::class);
        $prop = $ref->getProperty('requiresLock');
        $prop->setAccessible(true);
        $prop->setValue($cmd, true);

        ob_start();
        $cmd->run($args);
        ob_end_clean();

        // Lock should have been released.
        $this->assertFalse(\Tkmx\HfcmCli\Support\ExecutionLock::isLocked());
    }

    public function testRunReturnsInternalOnException(): void
    {
        $args = new Args([]);
        // Create a command that throws from execute().
        $cmd = new class($args) extends AbstractCommand {
            protected function commandName(): string { return 'test:throws'; }
            protected function execute(Args $args): int
            {
                throw new \RuntimeException('test error');
            }
        };

        ob_start();
        $code = $cmd->run($args);
        ob_end_clean();

        $this->assertSame(ExitCode::INTERNAL, $code);
    }

    public function testActorContextIsPassedToAudit(): void
    {
        // Verify that Bootstrap actor context flows into CliAudit without error.
        // We cannot assert DB writes (no Audit_Logger loaded), but run() must complete.
        $GLOBALS['_hfcm_wp_user_login'] = 'admin';
        $GLOBALS['_hfcm_unix_user']     = 'www-data';

        $args = new Args([]);
        $cmd  = new ConcreteCommand($args);

        ob_start();
        $code = $cmd->run($args);
        ob_end_clean();

        $this->assertSame(ExitCode::OK, $code);
    }

    public function testPayloadExceptionUsageCodePreserved(): void
    {
        // PayloadException with USAGE exit code must NOT be swallowed as INTERNAL.
        // Regression test for AbstractCommand::catch(PayloadException) ordering.
        $args = new Args([]);
        $cmd  = new class($args) extends AbstractCommand {
            protected function commandName(): string { return 'test:payload-usage'; }
            protected function execute(Args $args): int
            {
                throw new PayloadException('no source provided', ExitCode::USAGE);
            }
        };

        ob_start();
        $code = $cmd->run($args);
        ob_end_clean();

        $this->assertSame(ExitCode::USAGE, $code);
    }

    public function testPayloadExceptionErrorCodePreserved(): void
    {
        // PayloadException with ERROR exit code must return ERROR, not INTERNAL.
        $args = new Args([]);
        $cmd  = new class($args) extends AbstractCommand {
            protected function commandName(): string { return 'test:payload-error'; }
            protected function execute(Args $args): int
            {
                throw new PayloadException('invalid JSON', ExitCode::ERROR);
            }
        };

        ob_start();
        $code = $cmd->run($args);
        ob_end_clean();

        $this->assertSame(ExitCode::ERROR, $code);
    }
}
