<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Commands;

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;

class SnippetsDelete extends AbstractCommand
{
    protected function commandName(): string
    {
        return 'snippets:delete';
    }

    public function run(Args $args): int
    {
        // 一括削除（--ids）は実行ロックが必要; 単一削除（--id）は不要
        // ミラー: REST レイヤーは単一削除でもロックしない
        $this->requiresLock = $args->getString('ids') !== '';
        return parent::run($args);
    }

    protected function execute(Args $args): int
    {
        $singleId = $args->getString('id');
        $multiIds = $args->getString('ids');

        if ($singleId !== '' && $multiIds !== '') {
            $this->output->error(
                ['code' => 'invalid_args', 'message' => '単一削除には --id、一括削除には --ids を使用してください（両方は不可）'],
                '単一削除には --id、一括削除には --ids を使用してください（両方は不可）'
            );
            return ExitCode::USAGE;
        }

        // 単一削除
        if ($singleId !== '') {
            $result = \HFCM_Takumi_API_Snippet_Service::delete_snippet((int) $singleId);
            if (is_wp_error($result)) {
                return $this->handleWpError($result);
            }
            $this->output->success(['deleted' => [(int) $singleId]]);
            return ExitCode::OK;
        }

        // 一括削除
        if ($multiIds !== '') {
            $ids = array_filter(
                array_map('intval', explode(',', $multiIds)),
                fn(int $id): bool => $id > 0
            );

            if (count($ids) === 0) {
                $this->output->error(
                    ['code' => 'invalid_ids', 'message' => '--ids はカンマ区切りの正の整数のリストである必要があります'],
                    '--ids はカンマ区切りの正の整数のリストである必要があります'
                );
                return ExitCode::USAGE;
            }

            if (count($ids) > 100) {
                $this->output->error(
                    ['code' => 'bulk_delete_too_large', 'message' => '一括削除あたり最大 100 ID まで'],
                    '一括削除あたり最大 100 ID まで'
                );
                return ExitCode::ERROR;
            }

            $deleted = [];
            $errors  = [];
            foreach ($ids as $id) {
                $result = \HFCM_Takumi_API_Snippet_Service::delete_snippet($id);
                if (is_wp_error($result)) {
                    $errors[] = ['id' => $id, 'error' => $result->get_error_message()];
                } else {
                    $deleted[] = $id;
                }
            }

            $this->output->success([
                'deleted' => $deleted,
                'errors'  => $errors,
            ]);
            return empty($errors) ? ExitCode::OK : ExitCode::ERROR;
        }

        $this->output->error(
            ['code' => 'missing_args', 'message' => '--id または --ids が必須です'],
            '--id=<id> または --ids=1,2,3 が必須です'
        );
        return ExitCode::USAGE;
    }
}
