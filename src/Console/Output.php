<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Console;

class Output
{
    private bool $pretty;
    private bool $quiet;

    public function __construct(bool $pretty = false, bool $quiet = false)
    {
        $this->pretty = $pretty;
        $this->quiet  = $quiet;
    }

    /**
     * STDOUT に成功レスポンスを書き込む
     * @param mixed $data
     * @param array<string, mixed> $meta
     */
    public function success(mixed $data, array $meta = [], string $format = 'json'): void
    {
        $payload = [
            'success' => true,
            'data'    => $data,
            'meta'    => $meta,
        ];

        if ($format === 'table' && is_array($data)) {
            $this->writeTable($data);
        } else {
            $this->writeJson($payload);
        }
    }

    /**
     * STDOUT にエラーを JSON で、STDERR に人間が読める形で書き込む
     * @param array<string, mixed> $error
     */
    public function error(array $error, string $humanMessage = ''): void
    {
        $payload = [
            'success' => false,
            'error'   => $error,
        ];
        $this->writeJson($payload);

        if (!$this->quiet && $humanMessage !== '') {
            fwrite(STDERR, "Error: {$humanMessage}\n");
        }
    }

    /**
     * STDERR にプレーンメッセージを書き込む（JSON 出力としてキャプチャされない）
     */
    public function stderr(string $message): void
    {
        if (!$this->quiet) {
            fwrite(STDERR, $message . "\n");
        }
    }

    /** @param mixed $payload JSON ペイロード */
    private function writeJson(mixed $payload): void
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($this->pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        echo json_encode($payload, $flags) . "\n";
    }

    /**
     * 連想配列のリストを ASCII テーブルとしてレンダリング
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeTable(array $rows): void
    {
        if (empty($rows)) {
            echo "(レコードなし)\n";
            return;
        }

        // 表示用にネストされた配列を文字列にフラット化
        $flat = array_map(function (array $row): array {
            return array_map(fn($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v, $row);
        }, $rows);

        $headers = array_keys($flat[0]);
        $widths  = [];

        foreach ($headers as $h) {
            $widths[$h] = strlen((string) $h);
        }
        foreach ($flat as $row) {
            foreach ($headers as $h) {
                $widths[$h] = max($widths[$h], strlen($row[$h] ?? ''));
            }
        }

        $sep = '+' . implode('+', array_map(fn(int $w): string => str_repeat('-', $w + 2), $widths)) . '+';
        echo $sep . "\n";
        echo '| ' . implode(' | ', array_map(fn(string $h): string => str_pad($h, $widths[$h]), $headers)) . " |\n";
        echo $sep . "\n";
        foreach ($flat as $row) {
            echo '| ' . implode(' | ', array_map(fn(string $h): string => str_pad($row[$h] ?? '', $widths[$h]), $headers)) . " |\n";
        }
        echo $sep . "\n";
    }
}
