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

        if ($format === 'csv') {
            $result = \HFCM_Takumi_API_Export_Service::export_csv();
        } else {
            $result = \HFCM_Takumi_API_Export_Service::export_json();
        }

        if (is_wp_error($result)) {
            return $this->handleWpError($result);
        }

        if ($outPath !== '') {
            // Write to file
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

        // Output to STDOUT
        if ($format === 'csv') {
            // CSV is already a string from export_csv()
            echo is_string($result) ? $result : '';
        } else {
            $this->output->success($result);
        }

        return ExitCode::OK;
    }
}
