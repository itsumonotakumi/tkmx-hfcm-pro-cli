<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Support;

use Tkmx\HfcmCli\Console\ExitCode;

/**
 * Thrown by PayloadLoader on any load/parse failure.
 * Caught in bin/hfcm and converted to the appropriate exit code.
 */
class PayloadException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $exitCode = ExitCode::ERROR,
    ) {
        parent::__construct($message);
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }
}
