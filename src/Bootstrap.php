<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli;

use Tkmx\HfcmCli\Console\ExitCode;

class Bootstrap
{
    /**
     * Actor context populated during init(); consumed by CliAudit::start().
     * Keys: unix_user, wp_user_login, impersonated_login (null if not impersonating).
     *
     * @var array{unix_user: string, wp_user_login: string, impersonated_login: string|null}|null
     */
    private static ?array $actorContext = null;

    /**
     * Initialise the WordPress environment and establish a user context.
     *
     * @param list<string> $argv
     */
    public static function init(array $argv): void
    {
        // Parse --as early so we can validate HFCM_CLI_ALLOW_AS before WP loads.
        $asLogin = self::extractFlag($argv, '--as');

        // Strict check: only literal '1' enables impersonation.
        $allowAsEnv    = getenv('HFCM_CLI_ALLOW_AS');
        $allowAsDefine = defined('HFCM_CLI_ALLOW_AS') ? constant('HFCM_CLI_ALLOW_AS') : null;
        $allowAs       = ('1' === (string) $allowAsEnv) || ('1' === (string) $allowAsDefine);

        if ($asLogin !== null && !$allowAs) {
            fwrite(STDERR, "Error: --as requires HFCM_CLI_ALLOW_AS=1 to be set\n");
            exit(ExitCode::USAGE);
        }

        // Load config/cli.local.php if present (validated for permissions first).
        $localConfig = __DIR__ . '/../config/cli.local.php';
        if (file_exists($localConfig)) {
            self::validateConfigFile($localConfig);
            require $localConfig;
        }

        // Locate and load wp-load.php (up to 4 levels above this directory).
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

        // Validate against allowlist when HFCM_CLI_ALLOWED_AS_USERS is defined.
        if ($asLogin !== null && defined('HFCM_CLI_ALLOWED_AS_USERS')) {
            $allowedUsers = HFCM_CLI_ALLOWED_AS_USERS;
            if (is_array($allowedUsers) && count($allowedUsers) > 0) {
                if (!in_array($asLogin, $allowedUsers, true)) {
                    fwrite(STDERR, "Error: User '" . addslashes($asLogin) . "' is not in HFCM_CLI_ALLOWED_AS_USERS allowlist.\n");
                    exit(ExitCode::FORBIDDEN);
                }
            }
        }

        $user = get_user_by('login', $targetLogin);
        if (!$user) {
            fwrite(STDERR, "Error: WordPress user not found: " . addslashes($targetLogin) . "\n");
            exit(ExitCode::FORBIDDEN);
        }

        wp_set_current_user($user->ID);

        // Capability check is delegated to AbstractCommand::run() per command's
        // requiredCap property. Bootstrap only ensures the user is valid.
        // (read-only commands like list/get require 'read', not 'manage_options'.)

        // Record actor context for CliAudit.
        $unixUser = get_current_user() ?: (function_exists('posix_getlogin') ? @posix_getlogin() : '');
        self::$actorContext = [
            'unix_user'          => (string) $unixUser,
            'wp_user_login'      => $user->user_login,
            'impersonated_login' => ($asLogin !== null && $asLogin !== ($defaultUser ?: '')) ? $asLogin : null,
        ];
    }

    /**
     * Return the actor context populated during init().
     * Called by AbstractCommand to pass impersonation info to CliAudit::start().
     *
     * @return array{unix_user: string, wp_user_login: string, impersonated_login: string|null}|null
     */
    public static function getActorContext(): ?array
    {
        return self::$actorContext;
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
     * Rejects symlinks. Performs a basic TOCTOU guard by re-stat after first check.
     */
    private static function validateConfigFile(string $path): void
    {
        if (!function_exists('posix_getuid')) {
            fwrite(STDERR, "Error: POSIX拡張が必要。config/cli.local.php の権限検証を実行できません。\n");
            exit(ExitCode::FORBIDDEN);
        }

        // Reject symlinks (lstat checks the link itself, not the target).
        if (is_link($path)) {
            fwrite(STDERR, "Error: config/cli.local.php はシンボリックリンクであってはなりません\n");
            exit(ExitCode::FORBIDDEN);
        }

        $stat1 = @lstat($path);
        if ($stat1 === false) {
            fwrite(STDERR, "Error: cannot stat config file: {$path}\n");
            exit(ExitCode::FORBIDDEN);
        }

        $fileOwner    = $stat1['uid'];
        $processOwner = posix_getuid();

        if ($fileOwner !== $processOwner) {
            fwrite(STDERR, "Error: config/cli.local.php must be owned by the current user (uid mismatch)\n");
            exit(ExitCode::FORBIDDEN);
        }

        $mode = $stat1['mode'] & 0777;
        if ($mode !== 0600) {
            fwrite(STDERR, sprintf("Error: config/cli.local.php must have mode 0600 (found: %04o)\n", $mode));
            exit(ExitCode::FORBIDDEN);
        }

        // TOCTOU guard: re-stat and confirm inode has not changed.
        $stat2 = @lstat($path);
        if ($stat2 === false || $stat2['ino'] !== $stat1['ino'] || $stat2['dev'] !== $stat1['dev']) {
            fwrite(STDERR, "Error: config/cli.local.php changed between permission checks (possible TOCTOU attack)\n");
            exit(ExitCode::FORBIDDEN);
        }
    }

    /**
     * Extract a named flag value from argv (e.g. --as=admin or --as admin).
     * Treats a lone '-' token as a value (e.g. --file -).
     *
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
                $next = $argv[$i + 1];
                // Accept lone '-' as value; reject other flag tokens.
                if ($next === '-' || !str_starts_with($next, '-')) {
                    return $next;
                }
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
