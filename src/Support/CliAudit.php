<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Support;

use Tkmx\HfcmCli\Console\Args;

/**
 * CLI 呼び出し用に HFCM_Takumi_API_Audit_Logger::log() をラップ
 * アクター（UNIX ユーザー + WP user_login）、コマンド、編集済み args、結果を記録
 *
 * 実際の DB レコードは 1 つだけ書き込まれる: finish() 時点（成功またはエラー）
 * これにより監査ログはクリーンに保たれる — 偽の 500 ステータス "start" エントリなし
 */
class CliAudit
{
    private string $command;
    private float $startedAt;
    /** @var array<string, mixed> */
    private array $meta;

    public function __construct(string $command)
    {
        $this->command   = $command;
        $this->startedAt = microtime(true);
        $this->meta      = [];
    }

    /**
     * コマンド開始コンテキストを記録（メモリに保存; DB には書き込まない）
     *
     * @param array<string, mixed> $redactedArgs
     * @param array<string, mixed> $extra  例: ['actor' => ..., 'impersonated_login' => ...]
     */
    public function start(array $redactedArgs, array $extra = []): void
    {
        // UNIX ユーザーと WP user_login を収集
        $unixUser = get_current_user() ?: (function_exists('posix_getlogin') ? posix_getlogin() : '');
        $wpLogin  = '';
        if (function_exists('wp_get_current_user')) {
            $wpUser  = wp_get_current_user();
            $wpLogin = $wpUser->user_login ?? '';
        }

        $this->meta = array_merge([
            'unix_user'    => $unixUser,
            'wp_user_login' => $wpLogin,
            'redacted_args' => $redactedArgs,
        ], $extra);

        // ここで DB 書き込みなし — 'info' ステータスが 500 コード監査行を引き起こすのを避ける
        // 監査レコードは finish() で 1 回書き込まれる
        if (!class_exists('HFCM_Takumi_API_Audit_Logger')) {
            error_log('[hfcm-cli] 警告: HFCM_Takumi_API_Audit_Logger が読み込まれていません; 監査ログは無効です。');
        }
    }

    /**
     * このコマンド呼び出しの単一監査レコードを書き込む
     *
     * @param array<string, mixed> $summary
     */
    public function finish(int $exitCode, array $summary = []): void
    {
        if (!class_exists('HFCM_Takumi_API_Audit_Logger')) {
            error_log('[hfcm-cli] Audit_Logger クラスが読み込まれていません; 監査レコードをスキップ');
            return;
        }

        $durationMs = (int) round((microtime(true) - $this->startedAt) * 1000);
        $status     = $exitCode === 0 ? 'success' : 'error';

        $payload = array_merge($this->meta, [
            'exit_code'   => $exitCode,
            'duration_ms' => $durationMs,
            'summary'     => $summary,
        ]);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // フォールバック: エラー時に部分出力でエンコード、Logger に false を渡すのを避ける
            $json = json_encode(
                array_merge($this->meta, [
                    'exit_code'    => $exitCode,
                    'duration_ms'  => $durationMs,
                    'encode_error' => json_last_error_msg(),
                ]),
                JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            ) ?: '{"error":"json_encode_failed"}';
        }

        \HFCM_Takumi_API_Audit_Logger::log(
            'cli:' . $this->command,
            $status,
            $json
        );
    }
}
