<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Console;

class Args
{
    /** @var array<string, string|true> */
    private array $options = [];

    /** @var list<string> */
    private array $positional = [];

    /**
     * @param list<string> $argv  argv[0]（スクリプト名）を除いた argv
     */
    public function __construct(array $argv)
    {
        $this->parse($argv);
    }

    /** @param list<string> $argv */
    private function parse(array $argv): void
    {
        $i = 0;
        $count = count($argv);
        while ($i < $count) {
            $token = $argv[$i];

            // --key=value
            if (str_starts_with($token, '--') && str_contains($token, '=')) {
                [$key, $value] = explode('=', substr($token, 2), 2);
                $this->options[$key] = $value;
                $i++;
                continue;
            }

            // --key value または --flag
            if (str_starts_with($token, '--')) {
                $key  = substr($token, 2);
                $next = $argv[$i + 1] ?? null;
                // ロー '-' を値として受け入れ（例：--file - は STDIN を意味する）
                // '-' で始まるトークン（フラグ）を拒否、ただしロー '-' を除く
                if ($next !== null && ($next === '-' || !str_starts_with($next, '-'))) {
                    $this->options[$key] = $next;
                    $i += 2;
                } else {
                    $this->options[$key] = true;
                    $i++;
                }
                continue;
            }

            // -k value（単一文字の短いオプション）
            if (str_starts_with($token, '-') && strlen($token) === 2) {
                $key  = substr($token, 1);
                $next = $argv[$i + 1] ?? null;
                if ($next !== null && ($next === '-' || !str_starts_with($next, '-'))) {
                    $this->options[$key] = $next;
                    $i += 2;
                } else {
                    $this->options[$key] = true;
                    $i++;
                }
                continue;
            }

            $this->positional[] = $token;
            $i++;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->options[$key]);
    }

    public function positional(int $index): ?string
    {
        return $this->positional[$index] ?? null;
    }

    /** @return list<string> */
    public function allPositional(): array
    {
        return $this->positional;
    }

    public function getString(string $key, string $default = ''): string
    {
        $v = $this->options[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $v = $this->options[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        return is_numeric($v) ? (int) $v : $default;
    }

    public function getBool(string $key): bool
    {
        return isset($this->options[$key]) && $this->options[$key] !== false;
    }

    /**
     * 監査ログ用に編集されたコピーを返す（機密値を削除）
     * @return array<string, mixed>
     */
    public function toRedactedArray(): array
    {
        $sensitive = ['data', 'password', 'secret', 'token', 'key', 'auth', 'credential', 'apikey', 'api_key', 'authorization', 'bearer', 'file', 'out'];
        $out = [];
        foreach ($this->options as $k => $v) {
            $out[$k] = in_array($k, $sensitive, true) ? '[編集済]' : $v;
        }
        return $out;
    }
}
