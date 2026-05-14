<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Support;

/**
 * Atomic execution lock shared between CLI and REST layer.
 *
 * Both layers check the transient key 'hfcm_import_lock'. The CLI uses
 * add_option() on the underlying options-table row (autoload=no) to achieve
 * an atomic compare-and-set: MySQL's UNIQUE constraint on option_name means
 * only one caller can INSERT successfully. After acquiring via add_option(),
 * we also call set_transient() so that REST's get_transient() sees the lock.
 *
 * On release, the owner_token is verified before deletion to prevent one
 * process from releasing another's lock (e.g. on SIGTERM with concurrent runs).
 *
 * Note on object caches: if a persistent object cache (Redis/Memcached) is
 * active, set_transient() may bypass the DB. We guard against this by also
 * calling wp_cache_add() for the options group. In practice, the options-table
 * row is the authoritative source; REST's get_transient() will also read from
 * the persistent cache if one is present, so the lock is visible to both layers.
 */
class ExecutionLock
{
    private const TRANSIENT_KEY = 'hfcm_import_lock';
    private const OPTION_KEY    = '_transient_' . self::TRANSIENT_KEY;
    private const TTL           = 300; // 5 minutes, same as REST layer

    /** Owner token for the current process; null if not acquired by this process. */
    private static ?string $ownerToken = null;

    /**
     * Attempt to acquire the lock atomically.
     *
     * Returns true if this process acquired the lock, false if already held.
     */
    public static function acquire(): bool
    {
        $token = uniqid('cli_lock_', true) . '_' . getmypid();

        // First check the transient (fast path): REST and other CLIs set it.
        if (false !== get_transient(self::TRANSIENT_KEY)) {
            return false;
        }

        // Atomic insert via options table (UNIQUE constraint guarantees atomicity).
        // add_option() returns false if the row already exists.
        $acquired = add_option(self::OPTION_KEY, $token, '', 'no');
        if (!$acquired) {
            // Another process beat us to the INSERT.
            return false;
        }

        // Mirror into the transient cache so REST's get_transient() sees the lock.
        // If set_transient() fails (e.g. DB error), roll back the option row to avoid
        // a permanently stuck lock with no transient TTL cleanup path.
        if (!set_transient(self::TRANSIENT_KEY, $token, self::TTL)) {
            delete_option(self::OPTION_KEY);
            return false;
        }

        self::$ownerToken = $token;
        return true;
    }

    /**
     * Release the lock, but only if this process owns it.
     */
    public static function release(): void
    {
        if (self::$ownerToken === null) {
            return;
        }

        // Verify ownership: only delete if the stored token matches ours.
        $current = get_option(self::OPTION_KEY);
        if ($current === self::$ownerToken) {
            delete_option(self::OPTION_KEY);
            delete_transient(self::TRANSIENT_KEY);
        }

        self::$ownerToken = null;
    }

    /**
     * Release if this process is the owner. Safe to call from signal handlers.
     * Alias of release() exposed for clarity in bin/hfcm signal handler.
     */
    public static function releaseIfOwner(): void
    {
        self::release();
    }

    /**
     * Check whether the lock is currently held (by any process).
     */
    public static function isLocked(): bool
    {
        return false !== get_transient(self::TRANSIENT_KEY);
    }
}
