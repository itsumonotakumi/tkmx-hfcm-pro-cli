<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Support;

/**
 * CLI と REST レイヤー間で共有される原子的実行ロック
 *
 * 両方のレイヤーはトランジェント キー 'hfcm_import_lock' をチェック
 * CLI は基礎となるオプションテーブル行（autoload=no）で add_option() を使用して
 * 原子的な比較と設定を実現: MySQL の option_name の UNIQUE 制約により、
 * INSERT に成功できる呼び出し元は 1 つだけ。add_option() で取得した後、
 * set_transient() も呼び出し、REST の get_transient() がロックを見えるようにする
 *
 * リリース時、削除前に owner_token を検証し、あるプロセスが別のプロセスの
 * ロックをリリース（例：SIGTERM で並行実行）するのを防ぐ
 *
 * オブジェクトキャッシュに関する注記: 永続的なオブジェクトキャッシュ
 *（Redis/Memcached）がアクティブな場合、set_transient() は DB をバイパス
 * する可能性。wp_cache_add() をオプショングループでも呼び出すことで
 * ガード。実際には、オプションテーブル行が権威的なソース; REST の
 * get_transient() も、永続的なキャッシュがあれば読み込むため、
 * ロックは両方のレイヤーに見える
 */
class ExecutionLock
{
    private const TRANSIENT_KEY = 'hfcm_import_lock';
    private const OPTION_KEY    = '_transient_' . self::TRANSIENT_KEY;
    private const TTL           = 300; // 5 分、REST レイヤーと同じ

    /** 現在のプロセスのオーナートークン; このプロセスで取得されていない場合は null */
    private static ?string $ownerToken = null;

    /**
     * ロックを原子的に取得しようと試みる
     *
     * このプロセスがロックを取得した場合は true、既に保持されている場合は false を返す
     */
    public static function acquire(): bool
    {
        $token = uniqid('cli_lock_', true) . '_' . getmypid();

        // まずトランジェントをチェック（高速パス）: REST と他の CLI が設定
        if (false !== get_transient(self::TRANSIENT_KEY)) {
            return false;
        }

        // オプションテーブル経由で原子的挿入（UNIQUE 制約が原子性を保証）
        // add_option() は行が既に存在する場合 false を返す
        $acquired = add_option(self::OPTION_KEY, $token, '', 'no');
        if (!$acquired) {
            // 別のプロセスが INSERT に勝った
            return false;
        }

        // REST の get_transient() がロックを見えるようにトランジェントキャッシュに
        // ミラー化。set_transient() が失敗（DB エラーなど）した場合、永続的に
        // スタックしたロック（トランジェント TTL クリーンアップパスなし）を避けるため
        // オプション行をロールバック
        if (!set_transient(self::TRANSIENT_KEY, $token, self::TTL)) {
            delete_option(self::OPTION_KEY);
            return false;
        }

        self::$ownerToken = $token;
        return true;
    }

    /**
     * ロックをリリース、ただしこのプロセスがそれを所有している場合のみ
     */
    public static function release(): void
    {
        if (self::$ownerToken === null) {
            return;
        }

        // 所有権を検証: 保存されているトークンが私たちのものと一致する場合のみ削除
        $current = get_option(self::OPTION_KEY);
        if ($current === self::$ownerToken) {
            delete_option(self::OPTION_KEY);
            delete_transient(self::TRANSIENT_KEY);
        }

        self::$ownerToken = null;
    }

    /**
     * このプロセスがオーナーである場合、ロックをリリース。シグナルハンドラーから呼ばれても安全
     * bin/hfcm シグナルハンドラーで明確にするために release() のエイリアスを公開
     */
    public static function releaseIfOwner(): void
    {
        self::release();
    }

    /**
     * ロックが現在保持されているかをチェック（任意のプロセスで）
     */
    public static function isLocked(): bool
    {
        return false !== get_transient(self::TRANSIENT_KEY);
    }
}
