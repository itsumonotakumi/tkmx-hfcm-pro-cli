<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Support;

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;

/**
 * Loads JSON payload from --data, --file, or STDIN.
 *
 * Size limits are intentionally absent: this CLI operates within a trusted
 * boundary (local filesystem, same server), so no size cap is enforced.
 * See DESIGN.md §PayloadLoader for rationale.
 *
 * @throws PayloadException on any load/parse failure (caught in bin/hfcm).
 */
class PayloadLoader
{
    /**
     * Load JSON payload from --data, --file, or STDIN (-).
     * Returns decoded array on success, throws PayloadException on error.
     *
     * @return array<string, mixed>
     * @throws PayloadException
     */
    public static function load(Args $args): array
    {
        // Priority 1: --data=<json>
        if ($args->has('data')) {
            $raw = $args->getString('data');
            return self::decodeJson($raw, 'inline --data');
        }

        $file = $args->getString('file');

        // Priority 2 (STDIN): --file=-
        if ($file === '-') {
            $raw = stream_get_contents(STDIN);
            if ($raw === false) {
                throw new PayloadException("failed to read from STDIN", ExitCode::ERROR);
            }
            return self::decodeJson($raw, 'STDIN');
        }

        // Priority 3: --file=<path>
        if ($file !== '') {
            return self::loadFile($file);
        }

        throw new PayloadException(
            "one of --data, --file, or --file=- (STDIN) is required",
            ExitCode::USAGE
        );
    }

    /** @return array<string, mixed>
     * @throws PayloadException
     */
    private static function loadFile(string $path): array
    {
        if (is_link($path)) {
            throw new PayloadException("symlinks are not allowed: " . basename($path), ExitCode::ERROR);
        }

        if (!is_readable($path)) {
            throw new PayloadException("file not readable: " . basename($path), ExitCode::ERROR);
        }

        $isGzip = str_ends_with($path, '.gz') || self::isGzipMagic($path);

        if ($isGzip) {
            $compressed = file_get_contents($path);
            if ($compressed === false) {
                throw new PayloadException("failed to read file: " . basename($path), ExitCode::ERROR);
            }
            $raw = @gzdecode($compressed);
            if ($raw === false) {
                throw new PayloadException("invalid_gzip - failed to decompress file: " . basename($path), ExitCode::ERROR);
            }
            return self::decodeJson($raw, basename($path));
        }

        // Plain file — no size limit (trusted boundary).
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new PayloadException("failed to read file: " . basename($path), ExitCode::ERROR);
        }

        return self::decodeJson($raw, basename($path));
    }

    /**
     * Detect gzip magic bytes (\x1f\x8b) without relying on extension.
     */
    private static function isGzipMagic(string $path): bool
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return false;
        }
        $magic = fread($fh, 2);
        fclose($fh);
        return $magic === "\x1f\x8b";
    }

    /**
     * JSON decode; throws PayloadException on error.
     *
     * @return array<string, mixed>
     * @throws PayloadException
     */
    private static function decodeJson(string $raw, string $source): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new PayloadException(
                "invalid JSON from {$source}: " . json_last_error_msg(),
                ExitCode::ERROR
            );
        }
        return $decoded;
    }
}
