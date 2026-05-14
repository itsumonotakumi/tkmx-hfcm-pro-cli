<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Commands;

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;
use Tkmx\HfcmCli\Support\PayloadLoader;

/**
 * PUT（全置換）と PATCH（部分更新）の両方のモードを処理
 * --mode=put（デフォルト）または --mode=patch を使用してください
 */
class SnippetsUpdate extends AbstractCommand
{
    protected function commandName(): string
    {
        // 監査ログで patch と put を区別し、トレーサビリティのため
        // commandName() は run() から呼ばれる; 抽象署名は Args を受け入れない
        // ため、raw argv を読む。呼び出しごとに 1 回呼ばれる
        $argv = $_SERVER['argv'] ?? [];

        // ケース 1: ユーザーが 'snippets:patch' ルートを呼び出した（argv[1]）
        if (($argv[1] ?? '') === 'snippets:patch') {
            return 'snippets:patch';
        }

        // ケース 2: ユーザーが 'snippets:update --mode=patch' または '--mode patch' を呼び出した
        foreach ($argv as $i => $tok) {
            if ($tok === '--mode=patch') {
                return 'snippets:patch';
            }
            if ($tok === '--mode' && ($argv[$i + 1] ?? '') === 'patch') {
                return 'snippets:patch';
            }
        }

        return 'snippets:update';
    }

    protected function execute(Args $args): int
    {
        $id = $args->positional(0) ?? $args->getString('id');
        if ($id === '' || $id === null) {
            $this->output->error(
                ['code' => 'missing_id', 'message' => 'スニペット ID が必須です'],
                '使用法: snippets:update <id> [--mode=put|patch] --file=data.json'
            );
            return ExitCode::USAGE;
        }

        $mode = $args->getString('mode', 'put');
        if (!in_array($mode, ['put', 'patch'], true)) {
            $this->output->error(
                ['code' => 'invalid_mode', 'message' => "--mode は 'put' または 'patch' である必要があります"],
                "--mode は 'put' または 'patch' である必要があります"
            );
            return ExitCode::USAGE;
        }

        $data = PayloadLoader::load($args);

        // PUT は完全に検証; PATCH は部分的に許可
        $isPartial = $mode === 'patch';
        $validation = \HFCM_Takumi_API_Validator::validate_snippet_data($data, $isPartial);
        if (is_wp_error($validation)) {
            return $this->handleWpError($validation);
        }

        $result = \HFCM_Takumi_API_Snippet_Service::update_snippet((int) $id, $data);

        if (is_wp_error($result)) {
            return $this->handleWpError($result);
        }

        $this->output->success($result);
        return ExitCode::OK;
    }
}
