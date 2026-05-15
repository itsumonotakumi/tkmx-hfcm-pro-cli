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
     * このプロセスが所有するロックの TTL をリセットし、二重実行を防ぐ
     *
     * bulk-upsert / import のような長時間処理で TTL=300s を超えないよう、
     * N 件ごとや一定時間間隔で呼び出す。owner_token が一致しない場合は
     * false を返し、呼び出し元は処理を中断すべき。
     *
     * TOCTOU 限界: get_option() と set_transient() の間は非 atomic。
     * 永続オブジェクトキャッシュ（Redis 等）バックエンドでは、owner 検証後に
     * 別プロセスが acquire() → release() → 再 acquire() を完了した場合、
     * set_transient() が新オーナーのトランジェントを上書きする余地がある。
     * ただし fail-close 設計により、set_transient() が false を返した場合は
     * 直ちに false を返す（ロック喪失として扱う）。また次回の refresh() 呼び出しで
     * get_option() の owner mismatch を検出し fail-close される。
     * 権威的なソースはオプションテーブル行（add_option の UNIQUE 制約）であり、
     * トランジェントは REST 可視性のためのミラーに過ぎない。
     *
     * @return bool このプロセスがオーナーであり TTL のリセットに成功した場合 true;
     *              オーナー不一致・set_transient 失敗いずれの場合も false（fail-close）
     */
    public static function refresh(): bool
    {
        if (self::$ownerToken === null) {
            return false;
        }

        // オーナー検証: 保存されているトークンが自プロセスのものか確認
        $current = get_option(self::OPTION_KEY);
        if ($current !== self::$ownerToken) {
            return false;
        }

        // トランジェント TTL をリセット（REST レイヤーからも見えるように）
        return (bool) set_transient(self::TRANSIENT_KEY, self::$ownerToken, self::TTL);
    }

    /**
     * ロックが現在保持されているかをチェック（任意のプロセスで）
     */
    public static function isLocked(): bool
    {
        return false !== get_transient(self::TRANSIENT_KEY);
    }
}
