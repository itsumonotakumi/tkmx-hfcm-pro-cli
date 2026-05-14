<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Commands;

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;

class SnippetsGet extends AbstractCommand
{
    protected string $requiredCap = 'read';

    protected function commandName(): string
    {
        return 'snippets:get';
    }

    protected function execute(Args $args): int
    {
        $id = $args->positional(0) ?? $args->getString('id');
        if ($id === '' || $id === null) {
            $this->output->error(
                ['code' => 'missing_id', 'message' => 'Snippet ID is required'],
                'Usage: snippets:get <id>'
            );
            return ExitCode::USAGE;
        }

        $result = \HFCM_Takumi_API_Snippet_Service::get_snippet((int) $id);

        if (is_wp_error($result)) {
            return $this->handleWpError($result);
        }

        $this->output->success($result);
        return ExitCode::OK;
    }
}
