<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli;

use Tkmx\HfcmCli\Console\ExitCode;

class Bootstrap
{
    /**
     * init() 時に設定され、CliAudit::start() で使用されるアクターコンテキスト
     * キー: unix_user, wp_user_login, impersonated_login（偽装していない場合は null）
     *
     * @var array{unix_user: string, wp_user_login: string, impersonated_login: string|null}|null
     */
    private static ?array $actorContext = null;

    /**
     * WordPress 環境を初期化し、ユーザーコンテキストを確立
     *
     * @param list<string> $argv
     */
    public static function init(array $argv): void
    {
        // --as をいち早くパースし、WP ロード前に HFCM_CLI_ALLOW_AS を検証
        $asLogin = self::extractFlag($argv, '--as');

        // 厳密チェック: リテラルの '1' だけが偽装を有効にする
        $allowAsEnv    = getenv('HFCM_CLI_ALLOW_AS');
        $allowAsDefine = defined('HFCM_CLI_ALLOW_AS') ? constant('HFCM_CLI_ALLOW_AS') : null;
        $allowAs       = ('1' === (string) $allowAsEnv) || ('1' === (string) $allowAsDefine);

        if ($asLogin !== null && !$allowAs) {
            fwrite(STDERR, "エラー: --as には HFCM_CLI_ALLOW_AS=1 が設定されている必要があります\n");
            exit(ExitCode::USAGE);
        }

        // config/cli.local.ini を優先読み込み。
        // 不在の場合は cli.local.php にフォールバック（deprecation warning 付き）。
        $localIni = __DIR__ . '/../config/cli.local.ini';
        $localPhp = __DIR__ . '/../config/cli.local.php';

        if (file_exists($localIni)) {
            self::validateConfigFile($localIni, 'cli.local.ini');
            $cfg = parse_ini_file($localIni, true, INI_SCANNER_TYPED);
            if ($cfg === false) {
                fwrite(STDERR, "エラー: config/cli.local.ini のパースに失敗しました。INI 構文を確認してください。\n");
                exit(ExitCode::FORBIDDEN);
            }
            self::applyIniConfig($cfg);
        } elseif (file_exists($localPhp)) {
            fwrite(STDERR, "[hfcm-cli] Deprecated: config/cli.local.php は将来のバージョンで削除されます。config/cli.local.ini へ移行してください。\n");
            self::validateConfigFile($localPhp, 'cli.local.php');
            $cfg = require $localPhp;
            if (!is_array($cfg)) {
                fwrite(STDERR, "エラー: config/cli.local.php は配列を return する必要があります（例: return [];）。副作用（DB/IO 等）は禁止です。\n");
                exit(ExitCode::FORBIDDEN);
            }
        }

        // wp-load.php を探す（このディレクトリから最大 4 レベル上）
        $wpLoad = self::findWpLoad(__DIR__ . '/../..');
        if ($wpLoad === null) {
            fwrite(STDERR, "エラー: wp-load.php が見つかりません。tkmx-hfcm-pro-cli/ を WordPress ルートの内側に配置してください。\n");
            exit(ExitCode::WP_LOAD_FAIL);
        }

        // WP ブート中のHTMLOutput を抑制
        define('WP_USE_THEMES', false);
        require_once $wpLoad;

        // 最小メモリを確保
        $memLimit = self::parseBytes((string) ini_get('memory_limit'));
        $minMem   = 256 * 1024 * 1024;
        if ($memLimit > 0 && $memLimit < $minMem) {
            ini_set('memory_limit', '256M');
        }

        set_time_limit(0);

        // プラグインがアクティブであることを確認
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!is_plugin_active('hfcm-pro-takumi-api/hfcm-pro-takumi-api.php')) {
            fwrite(STDERR, "エラー: TKMX HFCM Pro API プラグインがアクティブになっていません。\n");
            exit(ExitCode::PLUGIN_INACTIVE);
        }

        // ユーザーコンテキストを確立
        $defaultUser = getenv('HFCM_CLI_DEFAULT_USER') ?: (defined('HFCM_CLI_DEFAULT_USER') ? HFCM_CLI_DEFAULT_USER : '');

        $targetLogin = $asLogin ?? ($defaultUser !== '' ? $defaultUser : null);

        if ($targetLogin === null) {
            fwrite(STDERR, "エラー: ユーザーが指定されていません。HFCM_CLI_DEFAULT_USER を設定するか、--as を使用してください（HFCM_CLI_ALLOW_AS=1 が必要）。\n");
            exit(ExitCode::FORBIDDEN);
        }

        // HFCM_CLI_ALLOWED_AS_USERS が定義されている場合、許可リストに対して検証
        if ($asLogin !== null && defined('HFCM_CLI_ALLOWED_AS_USERS')) {
            $allowedUsers = HFCM_CLI_ALLOWED_AS_USERS;
            if (is_array($allowedUsers) && count($allowedUsers) > 0) {
                if (!in_array($asLogin, $allowedUsers, true)) {
                    fwrite(STDERR, "エラー: ユーザー '" . addslashes($asLogin) . "' は HFCM_CLI_ALLOWED_AS_USERS 許可リストにありません。\n");
                    exit(ExitCode::FORBIDDEN);
                }
            }
        }

        $user = get_user_by('login', $targetLogin);
        if (!$user) {
            fwrite(STDERR, "エラー: WordPress ユーザーが見つかりません: " . addslashes($targetLogin) . "\n");
            exit(ExitCode::FORBIDDEN);
        }

        wp_set_current_user($user->ID);

        // 権限チェックは AbstractCommand::run() でコマンドの requiredCap プロパティに
        // 従って委譲されます。Bootstrap はユーザーが有効であることを保証するだけです。
        // （list/get のような読み取り専用コマンドは 'manage_options' ではなく 'read' が必要）

        // CliAudit 用にアクターコンテキストを記録
        $unixUser = get_current_user() ?: (function_exists('posix_getlogin') ? @posix_getlogin() : '');
        self::$actorContext = [
            'unix_user'          => (string) $unixUser,
            'wp_user_login'      => $user->user_login,
            'impersonated_login' => ($asLogin !== null && $asLogin !== ($defaultUser ?: '')) ? $asLogin : null,
        ];
    }

    /**
     * init() 時に設定されたアクターコンテキストを返す
     * AbstractCommand から CliAudit::start() に偽装情報を渡すために呼び出される
     *
     * @return array{unix_user: string, wp_user_login: string, impersonated_login: string|null}|null
     */
    public static function getActorContext(): ?array
    {
        return self::$actorContext;
    }

    /**
     * 開始ディレクトリから上へ歩きながら wp-load.php を探す
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
     * config ファイルが現在のプロセスユーザーに所有され、権限が 0600 であることを検証
     * シンボリックリンクを拒否。最初のチェック後に再スタットして基本的な TOCTOU ガードを実施
     *
     * @param string $displayName エラーメッセージに表示するファイル名（例: 'cli.local.ini'）
     */
    private static function validateConfigFile(string $path, string $displayName = 'cli.local.php'): void
    {
        if (!function_exists('posix_getuid')) {
            fwrite(STDERR, "Error: POSIX拡張が必要。config/{$displayName} の権限検証を実行できません。\n");
            exit(ExitCode::FORBIDDEN);
        }

        // シンボリックリンクを拒否（lstat はリンク自体をチェック、ターゲットではない）
        if (is_link($path)) {
            fwrite(STDERR, "エラー: config/{$displayName} はシンボリックリンクであってはなりません\n");
            exit(ExitCode::FORBIDDEN);
        }

        $stat1 = @lstat($path);
        if ($stat1 === false) {
            fwrite(STDERR, "エラー: config ファイルをスタット できません: {$path}\n");
            exit(ExitCode::FORBIDDEN);
        }

        $fileOwner    = $stat1['uid'];
        $processOwner = posix_getuid();

        if ($fileOwner !== $processOwner) {
            fwrite(STDERR, "エラー: config/{$displayName} は現在のユーザーに所有される必要があります（uid 不一致）\n");
            exit(ExitCode::FORBIDDEN);
        }

        $mode = $stat1['mode'] & 0777;
        if ($mode !== 0600) {
            fwrite(STDERR, sprintf("エラー: config/{$displayName} の権限は 0600 である必要があります（実際: %04o）\n", $mode));
            exit(ExitCode::FORBIDDEN);
        }

        // TOCTOU ガード: 再スタットして inode が変更されていないことを確認
        $stat2 = @lstat($path);
        if ($stat2 === false || $stat2['ino'] !== $stat1['ino'] || $stat2['dev'] !== $stat1['dev']) {
            fwrite(STDERR, "エラー: 権限チェック中に config/{$displayName} が変更されました（可能な TOCTOU 攻撃）\n");
            exit(ExitCode::FORBIDDEN);
        }
    }

    /**
     * parse_ini_file で読み込んだ設定を PHP 定数にブリッジする。
     * 既知のキーのみ処理し、未定義の場合のみ define() する（CLI 環境変数 > INI の優先順位を維持）。
     *
     * @param array<string, mixed> $cfg parse_ini_file の戻り値（process_sections=true）
     */
    private static function applyIniConfig(array $cfg): void
    {
        // process_sections=true の場合、セクション名が文字列キーになる。
        // 既知のキーはトップレベルで直接参照し、セクション内のキーも探索する。
        // 注意: HFCM_CLI_ALLOWED_AS_USERS は配列値（[] 構文）のため、
        // セクションと区別するために既知キーリストを先に確認する。
        $knownKeys = ['HFCM_CLI_DEFAULT_USER', 'HFCM_CLI_ALLOW_AS', 'HFCM_CLI_ALLOWED_AS_USERS'];

        foreach ($knownKeys as $key) {
            // トップレベルに存在する場合はそのまま使用（セクションなし・フラット INI）
            if (array_key_exists($key, $cfg)) {
                $value = $cfg[$key];
            } else {
                // セクション配下を探索（セクション値は配列、かつキーが文字列）
                $value = null;
                $found = false;
                foreach ($cfg as $sectionKey => $sectionValue) {
                    if (is_array($sectionValue) && is_string($sectionKey) && array_key_exists($key, $sectionValue)) {
                        $value = $sectionValue[$key];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    continue;
                }
            }

            if (defined($key)) {
                continue; // 環境変数由来の define を上書きしない
            }

            // HFCM_CLI_ALLOW_AS: INI_SCANNER_TYPED で boolean に変換された値を拒否。
            // リテラル '1'（文字列）または 1（整数）のみ有効とし、それ以外は '0' で無効化。
            if ($key === 'HFCM_CLI_ALLOW_AS') {
                if ($value === '1' || $value === 1) {
                    define($key, '1');
                } else {
                    fwrite(STDERR, "警告: cli.local.ini: HFCM_CLI_ALLOW_AS に無効な値が指定されました（'1' のみ有効）。偽装を無効化します。\n");
                    define($key, '0');
                }
                continue;
            }

            // HFCM_CLI_ALLOWED_AS_USERS: 配列以外（スカラー等）は fail-close。
            // '[] = ' 構文を使わずにスカラーとして書いた場合に素通りする脆弱性を防ぐ。
            if ($key === 'HFCM_CLI_ALLOWED_AS_USERS') {
                if (!is_array($value)) {
                    fwrite(STDERR, "エラー: cli.local.ini: HFCM_CLI_ALLOWED_AS_USERS は配列である必要があります（'HFCM_CLI_ALLOWED_AS_USERS[] = \"username\"' 構文を使用してください）\n");
                    exit(ExitCode::FORBIDDEN);
                }
            }

            define($key, $value);
        }
    }

    /**
     * argv から名前付きフラグ値を抽出（例: --as=admin または --as admin）
     * ロー '-' トークンを値として扱う（例: --file -）
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
                // ロー '-' を値として受け入れ; 他のフラグトークンを拒否
                if ($next === '-' || !str_starts_with($next, '-')) {
                    return $next;
                }
            }
        }
        return null;
    }

    /**
     * PHP メモリ短縮形をバイトに変換
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
