<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Support;

/**
 * Wraps the shared hfcm_import_lock transient used by both REST and CLI.
 * CLI and REST are mutually exclusive via this common key.
 */
class ExecutionLock
{
    private const KEY = 'hfcm_import_lock';
    private const TTL = 300; // 5 minutes, same as REST layer

    public static function acquire(): bool
    {
        if (false !== get_transient(self::KEY)) {
            return false;
        }
        set_transient(self::KEY, time(), self::TTL);
        return true;
    }

    public static function release(): void
    {
        delete_transient(self::KEY);
    }

    public static function isLocked(): bool
    {
        return false !== get_transient(self::KEY);
    }
}
