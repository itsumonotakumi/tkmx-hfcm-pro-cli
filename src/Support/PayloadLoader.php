<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Support;

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;

class PayloadLoader
{
    private const MAX_COMPRESSED   = 5 * 1024 * 1024;  // 5 MB
    private const MAX_UNCOMPRESSED = 10 * 1024 * 1024; // 10 MB

    /**
     * Load JSON payload from --data, --file, or STDIN (-).
     * Returns decoded array on success, exits on error.
     *
     * @return array<string, mixed>
     */
    public static function load(Args $args): array
    {
        // Priority 1: --data=<json>
        if ($args->has('data')) {
            $raw = $args->getString('data');
            return self::decodeJson($raw, 'inline --data');
        }

        $file = $args->getString('file');

        // Priority 3: STDIN
        if ($file === '-') {
            $raw = stream_get_contents(STDIN);
            if ($raw === false) {
                fwrite(STDERR, "Error: failed to read from STDIN\n");
                exit(ExitCode::ERROR);
            }
            return self::decodeJson($raw, 'STDIN');
        }

        // Priority 2: --file=<path>
        if ($file !== '') {
            return self::loadFile($file);
        }

        fwrite(STDERR, "Error: one of --data, --file, or --file=- (STDIN) is required\n");
        exit(ExitCode::USAGE);
    }

    /** @return array<string, mixed> */
    private static function loadFile(string $path): array
    {
        if (!is_readable($path)) {
            fwrite(STDERR, "Error: file not readable: {$path}\n");
            exit(ExitCode::ERROR);
        }

        $size = filesize($path);
        $isGzip = str_ends_with($path, '.gz') || self::isGzipMagic($path);

        if ($isGzip) {
            if ($size > self::MAX_COMPRESSED) {
                fwrite(STDERR, "Error: payload_too_large - compressed file exceeds 5 MB limit\n");
                exit(ExitCode::ERROR);
            }
            $compressed = file_get_contents($path);
            if ($compressed === false) {
                fwrite(STDERR, "Error: failed to read file: {$path}\n");
                exit(ExitCode::ERROR);
            }
            $raw = @gzdecode($compressed);
            if ($raw === false) {
                fwrite(STDERR, "Error: invalid_gzip - failed to decompress file: {$path}\n");
                exit(ExitCode::ERROR);
            }
            if (strlen($raw) > self::MAX_UNCOMPRESSED) {
                fwrite(STDERR, "Error: payload_too_large - decompressed content exceeds 10 MB limit\n");
                exit(ExitCode::ERROR);
            }
            return self::decodeJson($raw, $path);
        }

        // Plain file
        if ($size > self::MAX_UNCOMPRESSED) {
            fwrite(STDERR, "Error: payload_too_large - file exceeds 10 MB limit\n");
            exit(ExitCode::ERROR);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            fwrite(STDERR, "Error: failed to read file: {$path}\n");
            exit(ExitCode::ERROR);
        }

        return self::decodeJson($raw, $path);
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
     * JSON decode with size guard and exit on error.
     * @return array<string, mixed>
     */
    private static function decodeJson(string $raw, string $source): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            fwrite(STDERR, "Error: invalid JSON from {$source}: " . json_last_error_msg() . "\n");
            exit(ExitCode::ERROR);
        }
        return $decoded;
    }
}
