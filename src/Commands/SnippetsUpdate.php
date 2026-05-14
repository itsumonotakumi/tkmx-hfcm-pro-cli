<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Commands;

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;
use Tkmx\HfcmCli\Support\PayloadLoader;

/**
 * Handles both PUT (full replace) and PATCH (partial update) modes.
 * Use --mode=put (default) or --mode=patch.
 */
class SnippetsUpdate extends AbstractCommand
{
    protected function commandName(): string
    {
        // Distinguish patch vs put in audit logs for traceability.
        // commandName() is called from run(); the abstract signature does not
        // accept Args, so we read raw argv. Called once per invocation.
        $argv = $_SERVER['argv'] ?? [];

        // Case 1: user invoked 'snippets:patch' route (argv[1]).
        if (($argv[1] ?? '') === 'snippets:patch') {
            return 'snippets:patch';
        }

        // Case 2: user invoked 'snippets:update --mode=patch' or '--mode patch'.
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
                ['code' => 'missing_id', 'message' => 'Snippet ID is required'],
                'Usage: snippets:update <id> [--mode=put|patch] --file=data.json'
            );
            return ExitCode::USAGE;
        }

        $mode = $args->getString('mode', 'put');
        if (!in_array($mode, ['put', 'patch'], true)) {
            $this->output->error(
                ['code' => 'invalid_mode', 'message' => "--mode must be 'put' or 'patch'"],
                "--mode must be 'put' or 'patch'"
            );
            return ExitCode::USAGE;
        }

        $data = PayloadLoader::load($args);

        // For PUT, validate fully; for PATCH, allow partial.
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
