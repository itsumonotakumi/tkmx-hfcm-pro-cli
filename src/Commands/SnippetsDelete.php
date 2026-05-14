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

    protected function execute(Args $args): int
    {
        $singleId = $args->getString('id');
        $multiIds = $args->getString('ids');

        if ($singleId !== '' && $multiIds !== '') {
            $this->output->error(
                ['code' => 'invalid_args', 'message' => 'Use --id for single delete or --ids for bulk delete, not both'],
                'Use --id for single delete or --ids for bulk delete, not both'
            );
            return ExitCode::USAGE;
        }

        // Single delete
        if ($singleId !== '') {
            $result = \HFCM_Takumi_API_Snippet_Service::delete_snippet((int) $singleId);
            if (is_wp_error($result)) {
                return $this->handleWpError($result);
            }
            $this->output->success(['deleted' => [(int) $singleId]]);
            return ExitCode::OK;
        }

        // Bulk delete
        if ($multiIds !== '') {
            $ids = array_filter(
                array_map('intval', explode(',', $multiIds)),
                fn(int $id): bool => $id > 0
            );

            if (count($ids) === 0) {
                $this->output->error(
                    ['code' => 'invalid_ids', 'message' => '--ids must be a comma-separated list of positive integers'],
                    '--ids must be a comma-separated list of positive integers'
                );
                return ExitCode::USAGE;
            }

            if (count($ids) > 100) {
                $this->output->error(
                    ['code' => 'bulk_delete_too_large', 'message' => 'Maximum 100 IDs per bulk delete'],
                    'Maximum 100 IDs per bulk delete'
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
            ['code' => 'missing_args', 'message' => 'Either --id or --ids is required'],
            'Either --id=<id> or --ids=1,2,3 is required'
        );
        return ExitCode::USAGE;
    }
}
