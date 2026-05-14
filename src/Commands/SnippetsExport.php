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
                ['code' => 'invalid_format', 'message' => "--format は 'json' または 'csv' である必要があります"],
                "--format は 'json' または 'csv' である必要があります"
            );
            return ExitCode::USAGE;
        }

        $outPath  = $args->getString('out', '');
        $force    = $args->getBool('force');

        // エクスポート実行前に --out パスを検証
        if ($outPath !== '') {
            // シンボリックリンクを無条件に拒否（--force でも）
            if (is_link($outPath)) {
                $this->output->error(
                    ['code' => 'symlink_rejected', 'message' => "出力パスはシンボリックリンクであってはなりません: {$outPath}"],
                    "出力パスはシンボリックリンクであってはなりません: {$outPath}"
                );
                return ExitCode::ERROR;
            }

            if (file_exists($outPath) && !$force) {
                $this->output->error(
                    ['code' => 'file_exists', 'message' => "出力ファイルは既に存在します: {$outPath}。上書きするには --force を使用してください。"],
                    "出力ファイルは既に存在します: {$outPath}。上書きするには --force を使用してください。"
                );
                return ExitCode::ERROR;
            }

            $dir = dirname($outPath);
            if (!is_writable($dir)) {
                $this->output->error(
                    ['code' => 'write_error', 'message' => "出力ディレクトリは書き込み可能ではありません: {$dir}"],
                    "出力ディレクトリは書き込み可能ではありません: {$dir}"
                );
                return ExitCode::ERROR;
            }
        }

        // オプションの --ids=1,2,3 フィルターをパース
        $idsRaw     = $args->getString('ids', '');
        $snippetIds = null;
        if ($idsRaw !== '') {
            $parsed = array_filter(
                array_map('intval', explode(',', $idsRaw)),
                fn(int $id): bool => $id > 0
            );
            if (count($parsed) === 0) {
                $this->output->error(
                    ['code' => 'invalid_ids', 'message' => '--ids はカンマ区切りの正の整数のリストである必要があります'],
                    '--ids はカンマ区切りの正の整数のリストである必要があります'
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
            // fopen で 'xb'（O_CREAT|O_EXCL）経由でアトミックにファイルに書き込み
            // 存在チェックと書き込み間の TOCTOU 攻撃を防ぐ
            // --force を使用する場合、既存ファイルを先に削除して排他的にオープン
            if ($force && file_exists($outPath)) {
                if (!unlink($outPath)) {
                    $this->output->error(
                        ['code' => 'write_error', 'message' => "既存ファイルを削除できませんでした: {$outPath}"],
                        "既存ファイルを削除できませんでした: {$outPath}"
                    );
                    return ExitCode::ERROR;
                }
            }

            $fh = fopen($outPath, 'xb');
            if ($fh === false) {
                $this->output->error(
                    ['code' => 'write_error', 'message' => "出力ファイルを作成できませんでした（既に存在する可能性があります）: {$outPath}"],
                    "出力ファイルを作成できませんでした（既に存在する可能性があります）: {$outPath}"
                );
                return ExitCode::ERROR;
            }

            $content = $format === 'json'
                ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                : $result;

            $written = fwrite($fh, (string) $content);
            fclose($fh);

            if ($written === false) {
                $this->output->error(
                    ['code' => 'write_error', 'message' => "書き込みに失敗しました: {$outPath}"],
                    "書き込みに失敗しました: {$outPath}"
                );
                return ExitCode::ERROR;
            }

            $this->output->success(['file' => $outPath, 'format' => $format]);
            return ExitCode::OK;
        }

        // STDOUT に出力
        // CSV はプレーンな文字列; JSON は構造化出力ヘルパーを使用
        if ($format === 'csv') {
            echo is_string($result) ? $result : '';
        } else {
            $this->output->success($result);
        }

        return ExitCode::OK;
    }
}
