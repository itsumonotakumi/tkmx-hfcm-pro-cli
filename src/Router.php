<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli;

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;
use Tkmx\HfcmCli\Commands\SnippetsList;
use Tkmx\HfcmCli\Commands\SnippetsGet;
use Tkmx\HfcmCli\Commands\SnippetsCreate;
use Tkmx\HfcmCli\Commands\SnippetsUpdate;
use Tkmx\HfcmCli\Commands\SnippetsDelete;
use Tkmx\HfcmCli\Commands\SnippetsBulkUpsert;
use Tkmx\HfcmCli\Commands\SnippetsImport;
use Tkmx\HfcmCli\Commands\SnippetsExport;

class Router
{
    /** @var array<string, class-string> コマンド名からコマンドクラスへのマッピング */
    private array $commands = [
        'snippets:list'        => SnippetsList::class,
        'snippets:get'         => SnippetsGet::class,
        'snippets:create'      => SnippetsCreate::class,
        'snippets:update'      => SnippetsUpdate::class,
        'snippets:patch'       => SnippetsUpdate::class,
        'snippets:delete'      => SnippetsDelete::class,
        'snippets:bulk-upsert' => SnippetsBulkUpsert::class,
        'snippets:import'      => SnippetsImport::class,
        'snippets:export'      => SnippetsExport::class,
    ];

    /**
     * @param list<string> $argv  argv[0] を含む完全な $argv
     */
    public function dispatch(array $argv): int
    {
        // argv[0] = スクリプト, argv[1] = コマンド, argv[2+] = 引数
        $command = $argv[1] ?? null;

        if ($command === null || $command === '--help' || $command === '-h' || $command === 'help') {
            $this->printHelp();
            return ExitCode::OK;
        }

        if (!isset($this->commands[$command])) {
            fwrite(STDERR, "エラー: 不明なコマンド '{$command}'\n\n");
            $this->printHelp();
            return ExitCode::USAGE;
        }

        // argv[2+] から Args を構築（スクリプトとコマンドトークンをスキップ）
        $argTokens = array_values(array_slice($argv, 2));
        $args      = new Args($argTokens);

        $class   = $this->commands[$command];
        $handler = new $class($args);

        return $handler->run($args);
    }

    private function printHelp(): void
    {
        echo <<<HELP
TKMX HFCM Pro CLI

Usage: hfcm <command> [options]

Commands:
  snippets:list          List all snippets
  snippets:get <id>      Get a single snippet
  snippets:create        Create a snippet (--file or --data required)
  snippets:update <id>   Update a snippet (PUT; use --mode=patch for PATCH)
  snippets:delete        Delete snippet(s) (--id=<id> or --ids=1,2,3)
  snippets:bulk-upsert   Bulk upsert snippets (--file or --data required)
  snippets:import        Import snippets (--file or --data required)
  snippets:export        Export snippets (--format=json|csv, --out=<path>)

Common Options:
  --format=json|table|csv  Output format (default: json)
  --pretty                 Pretty-print JSON
  --quiet                  Suppress STDERR messages
  --file=<path>            Load payload from file (.gz supported)
  --file=-                 Load payload from STDIN
  --data=<json>            Inline JSON payload
  --out=<path>             Write output to file (export only)
  --as=<user_login>        Run as a specific WP user (requires HFCM_CLI_ALLOW_AS=1)
  --help                   Show this help

HELP;
    }
}
