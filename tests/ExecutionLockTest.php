<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tkmx\HfcmCli\Support\ExecutionLock;

class ExecutionLockTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset in-memory transient/option store and owner token between tests.
        TransientStore::reset();
        // Reset static $ownerToken via reflection.
        $ref = new ReflectionClass(ExecutionLock::class);
        $prop = $ref->getProperty('ownerToken');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testAcquireSucceedsWhenNoLockHeld(): void
    {
        $this->assertTrue(ExecutionLock::acquire());
    }

    public function testAcquireFailsWhenTransientAlreadySet(): void
    {
        // Simulate another process having set the transient (e.g. REST layer).
        set_transient('hfcm_import_lock', time(), 300);
        $this->assertFalse(ExecutionLock::acquire());
    }

    public function testAcquireFailsWhenOptionAlreadyExists(): void
    {
        // Simulate another CLI having inserted the option atomically.
        add_option('_transient_hfcm_import_lock', 'other_token', '', 'no');
        // No transient set yet, but add_option will fail on duplicate.
        // The first get_transient check passes (no transient); add_option fails.
        $this->assertFalse(ExecutionLock::acquire());
    }

    public function testReleaseDeletesLockWhenOwner(): void
    {
        $this->assertTrue(ExecutionLock::acquire());
        $this->assertTrue(ExecutionLock::isLocked());
        ExecutionLock::release();
        $this->assertFalse(ExecutionLock::isLocked());
    }

    public function testReleaseDoesNotDeleteWhenNotOwner(): void
    {
        // Manually plant a token owned by another process.
        $foreignToken = 'foreign_token_99999';
        TransientStore::$transients['hfcm_import_lock'] = $foreignToken;
        TransientStore::$options['_transient_hfcm_import_lock'] = $foreignToken;

        // This process never called acquire(), so ownerToken is null.
        ExecutionLock::release();

        // Lock should still be held by the foreign token.
        $this->assertTrue(ExecutionLock::isLocked());
    }

    public function testReleaseOwnerMismatchLeavesLockIntact(): void
    {
        // Acquire and capture the token.
        $this->assertTrue(ExecutionLock::acquire());

        // Overwrite the stored option with a different token (race simulation).
        TransientStore::$options['_transient_hfcm_import_lock'] = 'different_token';

        // release() should detect mismatch and leave the option/transient alone.
        ExecutionLock::release();

        // The transient should still exist (we didn't delete it).
        $this->assertTrue(ExecutionLock::isLocked());
    }

    public function testIsLockedReturnsFalseInitially(): void
    {
        $this->assertFalse(ExecutionLock::isLocked());
    }

    public function testIsLockedReturnsTrueAfterAcquire(): void
    {
        ExecutionLock::acquire();
        $this->assertTrue(ExecutionLock::isLocked());
    }

    public function testReleaseIfOwnerIsAliasOfRelease(): void
    {
        $this->assertTrue(ExecutionLock::acquire());
        ExecutionLock::releaseIfOwner();
        $this->assertFalse(ExecutionLock::isLocked());
    }

    public function testDoubleAcquireByDifferentProcessFails(): void
    {
        // First acquire succeeds.
        $this->assertTrue(ExecutionLock::acquire());

        // Simulate a second process trying to acquire (same in-memory store).
        // Reset only the ownerToken so acquire() tries again without releasing.
        $ref = new ReflectionClass(ExecutionLock::class);
        $prop = $ref->getProperty('ownerToken');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // Second attempt: transient already set → returns false.
        $this->assertFalse(ExecutionLock::acquire());
    }

    // -----------------------------------------------------------------------
    // refresh() tests
    // -----------------------------------------------------------------------

    public function testRefreshReturnsTrueWhenOwner(): void
    {
        $this->assertTrue(ExecutionLock::acquire());
        // Should reset TTL and return true while we still own the lock.
        $this->assertTrue(ExecutionLock::refresh());
        // Lock must still be held after refresh.
        $this->assertTrue(ExecutionLock::isLocked());
    }

    public function testRefreshReturnsFalseWhenNotAcquired(): void
    {
        // Never called acquire() — ownerToken is null.
        $this->assertFalse(ExecutionLock::refresh());
    }

    public function testRefreshReturnsFalseOnOwnerMismatch(): void
    {
        $this->assertTrue(ExecutionLock::acquire());

        // Overwrite the stored option with a foreign token (race simulation).
        TransientStore::$options['_transient_hfcm_import_lock'] = 'foreign_token_99999';

        // refresh() detects mismatch and returns false.
        $this->assertFalse(ExecutionLock::refresh());
    }

    public function testRefreshExtendsLockTtl(): void
    {
        $this->assertTrue(ExecutionLock::acquire());

        // Simulate TTL expiry by removing the transient only (option stays).
        unset(TransientStore::$transients['hfcm_import_lock']);
        $this->assertFalse(ExecutionLock::isLocked());

        // refresh() re-sets the transient.
        $this->assertTrue(ExecutionLock::refresh());
        $this->assertTrue(ExecutionLock::isLocked());
    }
}
