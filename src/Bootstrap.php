<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli;

use Tkmx\HfcmCli\Console\ExitCode;

class Bootstrap
{
    /**
     * Initialise the WordPress environment and establish a user context.
     *
     * @param list<string> $argv
     */
    public static function init(array $argv): void
    {
        // Parse --as early so we can validate HFCM_CLI_ALLOW_AS before WP loads.
        $asLogin = self::extractFlag($argv, '--as');

        if ($asLogin !== null && !getenv('HFCM_CLI_ALLOW_AS')) {
            fwrite(STDERR, "Error: --as requires HFCM_CLI_ALLOW_AS=1 to be set\n");
            exit(ExitCode::USAGE);
        }

        // Load config/cli.local.php if present (validated for permissions first).
        $localConfig = __DIR__ . '/../config/cli.local.php';
        if (file_exists($localConfig)) {
            self::validateConfigFile($localConfig);
            require $localConfig;
        }

        // Locate and load wp-load.php (up to 3 levels above this directory).
        $wpLoad = self::findWpLoad(__DIR__ . '/../..');
        if ($wpLoad === null) {
            fwrite(STDERR, "Error: wp-load.php not found. Place tkmx-hfcm-pro-cli/ inside your WordPress root.\n");
            exit(ExitCode::WP_LOAD_FAIL);
        }

        // Suppress HTML output during WP boot.
        define('WP_USE_THEMES', false);
        require_once $wpLoad;

        // Ensure minimum memory.
        $memLimit = self::parseBytes((string) ini_get('memory_limit'));
        $minMem   = 256 * 1024 * 1024;
        if ($memLimit > 0 && $memLimit < $minMem) {
            ini_set('memory_limit', '256M');
        }

        set_time_limit(0);

        // Verify plugin is active.
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!is_plugin_active('hfcm-pro-takumi-api/hfcm-pro-takumi-api.php')) {
            fwrite(STDERR, "Error: TKMX HFCM Pro API plugin is not active.\n");
            exit(ExitCode::PLUGIN_INACTIVE);
        }

        // Establish user context.
        $defaultUser = getenv('HFCM_CLI_DEFAULT_USER') ?: (defined('HFCM_CLI_DEFAULT_USER') ? HFCM_CLI_DEFAULT_USER : '');

        $targetLogin = $asLogin ?? ($defaultUser !== '' ? $defaultUser : null);

        if ($targetLogin === null) {
            fwrite(STDERR, "Error: No user specified. Set HFCM_CLI_DEFAULT_USER or use --as (with HFCM_CLI_ALLOW_AS=1).\n");
            exit(ExitCode::FORBIDDEN);
        }

        $user = get_user_by('login', $targetLogin);
        if (!$user) {
            fwrite(STDERR, "Error: WordPress user not found: {$targetLogin}\n");
            exit(ExitCode::FORBIDDEN);
        }

        wp_set_current_user($user->ID);

        if (!current_user_can('manage_options')) {
            fwrite(STDERR, "Error: User '{$targetLogin}' does not have manage_options capability.\n");
            exit(ExitCode::FORBIDDEN);
        }

        // Record impersonation in audit if --as was used.
        if ($asLogin !== null && $asLogin !== ($defaultUser ?: '')) {
            // Stored for CliAudit to pick up if needed.
            // The actual audit happens per-command in AbstractCommand.
        }
    }

    /**
     * Find wp-load.php by walking up from a start directory.
     */
    private static function findWpLoad(string $startDir): ?string
    {
        $dir = realpath($startDir);
        if ($dir === false) {
            return null;
        }

        for ($i = 0; $i < 4; $i++) {
            $candidate = $dir . '/wp-load.php';
            if (file_exists($candidate)) {
                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return null;
    }

    /**
     * Validate that config file is owned by the current process user and mode 0600.
     */
    private static function validateConfigFile(string $path): void
    {
        if (!function_exists('posix_getuid')) {
            return; // Skip on non-POSIX systems.
        }

        $stat = stat($path);
        if ($stat === false) {
            fwrite(STDERR, "Error: cannot stat config file: {$path}\n");
            exit(ExitCode::FORBIDDEN);
        }

        $fileOwner    = $stat['uid'];
        $processOwner = posix_getuid();

        if ($fileOwner !== $processOwner) {
            fwrite(STDERR, "Error: config/cli.local.php must be owned by the current user (uid mismatch)\n");
            exit(ExitCode::FORBIDDEN);
        }

        $mode = $stat['mode'] & 0777;
        if ($mode !== 0600) {
            fwrite(STDERR, sprintf("Error: config/cli.local.php must have mode 0600 (found: %04o)\n", $mode));
            exit(ExitCode::FORBIDDEN);
        }
    }

    /**
     * Extract a named flag value from argv (e.g. --as=admin or --as admin).
     * @param list<string> $argv
     */
    private static function extractFlag(array $argv, string $flag): ?string
    {
        $prefix = $flag . '=';
        foreach ($argv as $i => $token) {
            if (str_starts_with($token, $prefix)) {
                return substr($token, strlen($prefix));
            }
            if ($token === $flag && isset($argv[$i + 1])) {
                return $argv[$i + 1];
            }
        }
        return null;
    }

    /**
     * Convert PHP memory shorthand to bytes.
     */
    private static function parseBytes(string $val): int
    {
        $val  = trim($val);
        $last = strtolower($val[-1] ?? '');
        $num  = (int) $val;
        return match ($last) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
