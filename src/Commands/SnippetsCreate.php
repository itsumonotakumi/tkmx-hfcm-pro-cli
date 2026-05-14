<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Commands;

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;
use Tkmx\HfcmCli\Support\PayloadLoader;

class SnippetsCreate extends AbstractCommand
{
    protected function commandName(): string
    {
        return 'snippets:create';
    }

    protected function execute(Args $args): int
    {
        $data = PayloadLoader::load($args);

        $validation = \HFCM_Takumi_API_Validator::validate_snippet_data($data, false);
        if (is_wp_error($validation)) {
            return $this->handleWpError($validation);
        }

        $result = \HFCM_Takumi_API_Snippet_Service::create_snippet($data);

        if (is_wp_error($result)) {
            return $this->handleWpError($result);
        }

        $this->output->success($result);
        return ExitCode::OK;
    }
}
