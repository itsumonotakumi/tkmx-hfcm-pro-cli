<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Commands;

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;
use Tkmx\HfcmCli\Support\PayloadLoader;

class SnippetsBulkUpsert extends AbstractCommand
{
    protected bool $requiresLock = true;

    protected function commandName(): string
    {
        return 'snippets:bulk-upsert';
    }

    protected function execute(Args $args): int
    {
        $data   = PayloadLoader::load($args);
        $result = \HFCM_Takumi_API_Bulk_Upsert_Service::process($data);

        if (is_wp_error($result)) {
            return $this->handleWpError($result);
        }

        $this->output->success($result);
        return ExitCode::OK;
    }
}
