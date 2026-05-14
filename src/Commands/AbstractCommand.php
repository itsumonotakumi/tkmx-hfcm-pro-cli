<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Commands;

use Tkmx\HfcmCli\Bootstrap;
use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;
use Tkmx\HfcmCli\Console\Output;
use Tkmx\HfcmCli\Support\CliAudit;
use Tkmx\HfcmCli\Support\ExecutionLock;
use Tkmx\HfcmCli\Support\PayloadException;
use Tkmx\HfcmCli\Support\PayloadLoader;
use Tkmx\HfcmCli\Support\WpErrorFormatter;

abstract class AbstractCommand
{
    protected Output $output;

    /** 排他的ロックが必要な書き込みコマンドの場合、true にオーバーライド */
    protected bool $requiresLock = false;

    /**
     * 必要な最小 WP 権限
     * 読み取り専用コマンド（例: 'read'）の場合はサブクラスでオーバーライド
     * Bootstrap はもう manage_options をグローバルに強制しない
     */
    protected string $requiredCap = 'manage_options';

    abstract protected function commandName(): string;

    abstract protected function execute(Args $args): int;

    public function __construct(Args $args)
    {
        $this->output = new Output(
            pretty: $args->getBool('pretty'),
            quiet: $args->getBool('quiet'),
        );
    }

    public function run(Args $args): int
    {
        $audit = new CliAudit($this->commandName());

        // 追加の監査コンテキストを構築: unix_user, wp_user_login, impersonated_login
        $actorContext = Bootstrap::getActorContext() ?? [];
        $extra = array_filter([
            'unix_user'          => $actorContext['unix_user'] ?? null,
            'wp_user_login'      => $actorContext['wp_user_login'] ?? null,
            'impersonated_login' => $actorContext['impersonated_login'] ?? null,
        ], fn($v) => $v !== null);

        $audit->start($args->toRedactedArray(), $extra);

        // コマンドごとの権限ガード（Bootstrap レベルの manage_options に置き換わる）
        if (!function_exists('current_user_can') || !current_user_can($this->requiredCap)) {
            $this->output->error(
                ['code' => 'rest_forbidden', 'message' => '権限が不足しています'],
                '権限が不足しています（必須: ' . $this->requiredCap . '）'
            );
            $audit->finish(ExitCode::FORBIDDEN);
            return ExitCode::FORBIDDEN;
        }

        $locked = false;
        if ($this->requiresLock) {
            if (!ExecutionLock::acquire()) {
                $this->output->error(
                    ['code' => 'import_in_progress', 'message' => '別のインポート/アップサートが既に実行中です'],
                    'インポート/アップサートが既に実行中です。後でもう一度お試しください。'
                );
                $audit->finish(ExitCode::TEMPFAIL);
                return ExitCode::TEMPFAIL;
            }
            $locked = true;
        }

        $exitCode    = ExitCode::INTERNAL;
        $payloadMeta = null;
        try {
            $exitCode    = $this->execute($args);
            $payloadMeta = PayloadLoader::consumeLastMeta();
        } catch (PayloadException $e) {
            // PayloadException は独自の終了コード（ERROR または USAGE）を持つ
            // Throwable の前にキャッチして INTERNAL として飲み込まれるのを避ける
            $this->output->error(
                ['code' => 'payload_error', 'message' => $e->getMessage()],
                $e->getMessage()
            );
            $exitCode = $e->getExitCode();
        } catch (\Throwable $e) {
            $this->output->error(
                ['code' => 'internal_error', 'message' => $e->getMessage()],
                $e->getMessage()
            );
            $exitCode = ExitCode::INTERNAL;
        } finally {
            if ($locked) {
                ExecutionLock::release();
            }
        }

        $summary = $payloadMeta !== null ? ['payload_meta' => $payloadMeta] : [];
        $audit->finish($exitCode, $summary);
        return $exitCode;
    }

    /**
     * WP_Error を処理: JSON エラー出力を書き込み、マップされた終了コードを返す
     */
    protected function handleWpError(\WP_Error $error): int
    {
        $arr      = WpErrorFormatter::toArray($error);
        $exitCode = WpErrorFormatter::toExitCode($error);
        $this->output->error($arr, $arr['message']);
        return $exitCode;
    }
}
