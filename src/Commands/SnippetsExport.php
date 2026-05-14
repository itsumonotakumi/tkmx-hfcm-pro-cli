<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Commands;

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;

class SnippetsExport extends AbstractCommand
{
    protected string $requiredCap = 'read';

    protected function commandName(): string
    {
        return 'snippets:export';
    }

    protected function execute(Args $args): int
    {
        $format = $args->getString('format', 'json');
        if (!in_array($format, ['json', 'csv'], true)) {
            $this->output->error(
                ['code' => 'invalid_format', 'message' => "--format must be 'json' or 'csv'"],
                "--format must be 'json' or 'csv'"
            );
            return ExitCode::USAGE;
        }

        $outPath = $args->getString('out', '');

        // Validate --out path before running the export.
        if ($outPath !== '') {
            if (is_file($outPath)) {
                $this->output->error(
                    ['code' => 'file_exists', 'message' => "Output file already exists: {$outPath}. Remove it first."],
                    "Output file already exists: {$outPath}. Remove it first."
                );
                return ExitCode::ERROR;
            }
            $dir = dirname($outPath);
            if (!is_writable($dir)) {
                $this->output->error(
                    ['code' => 'write_error', 'message' => "Output directory is not writable: {$dir}"],
                    "Output directory is not writable: {$dir}"
                );
                return ExitCode::ERROR;
            }
        }

        // Parse optional --ids=1,2,3 filter.
        $idsRaw     = $args->getString('ids', '');
        $snippetIds = null;
        if ($idsRaw !== '') {
            $parsed = array_filter(
                array_map('intval', explode(',', $idsRaw)),
                fn(int $id): bool => $id > 0
            );
            if (count($parsed) === 0) {
                $this->output->error(
                    ['code' => 'invalid_ids', 'message' => '--ids must be a comma-separated list of positive integers'],
                    '--ids must be a comma-separated list of positive integers'
                );
                return ExitCode::USAGE;
            }
            $snippetIds = array_values($parsed);
        }

        if ($format === 'csv') {
            $result = \HFCM_Takumi_API_Export_Service::export_csv($snippetIds);
        } else {
            $result = \HFCM_Takumi_API_Export_Service::export_json($snippetIds);
        }

        if (is_wp_error($result)) {
            return $this->handleWpError($result);
        }

        if ($outPath !== '') {
            // Write to file.
            $content = $format === 'json'
                ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                : $result;

            if (file_put_contents($outPath, $content) === false) {
                $this->output->error(
                    ['code' => 'write_error', 'message' => "Failed to write to: {$outPath}"],
                    "Failed to write to: {$outPath}"
                );
                return ExitCode::ERROR;
            }

            $this->output->success(['file' => $outPath, 'format' => $format]);
            return ExitCode::OK;
        }

        // Output to STDOUT.
        // CSV is a raw string; JSON uses the structured output helper.
        if ($format === 'csv') {
            echo is_string($result) ? $result : '';
        } else {
            $this->output->success($result);
        }

        return ExitCode::OK;
    }
}
