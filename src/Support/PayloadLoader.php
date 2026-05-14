<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Support;

use Tkmx\HfcmCli\Console\Args;
use Tkmx\HfcmCli\Console\ExitCode;

/**
 * --data、--file、または STDIN から JSON ペイロードを読み込む
 *
 * サイズ制限は意図的に不在: この CLI は信頼できる境界（ローカルファイルシステム、
 * 同じサーバー）内で動作するため、サイズキャップは強制されない
 * 詳細は DESIGN.md §PayloadLoader を参照
 *
 * @throws PayloadException 読み込み/解析失敗時（bin/hfcm でキャッチ）
 */
class PayloadLoader
{
    /**
     * 最後に正常に読み込まれたペイロードについてのメタデータ
     * load() で設定; AbstractCommand で consumeLastMeta() で使用
     * キー: bytes（int）、sha256（文字列）
     *
     * @var array{bytes: int, sha256: string}|null
     */
    private static ?array $lastMeta = null;

    /**
     * 最後の load() 呼び出し時に記録されたペイロードメタデータを返して削除
     * load() が呼ばれていないか、投げられた場合は null を返す
     *
     * @return array{bytes: int, sha256: string}|null
     */
    public static function consumeLastMeta(): ?array
    {
        $meta = self::$lastMeta;
        self::$lastMeta = null;
        return $meta;
    }

    /**
     * --data、--file、または STDIN（-）から JSON ペイロードを読み込む
     * 成功時はデコード済み配列を返し、エラー時は PayloadException を投げる
     *
     * @return array<string, mixed>
     * @throws PayloadException
     */
    public static function load(Args $args): array
    {
        self::$lastMeta = null;
        // 優先度 1: --data=<json>
        if ($args->has('data')) {
            $raw = $args->getString('data');
            return self::decodeJson($raw, 'インライン --data');
        }

        $file = $args->getString('file');

        // 優先度 2（STDIN）: --file=-
        if ($file === '-') {
            $raw = stream_get_contents(STDIN);
            if ($raw === false) {
                throw new PayloadException("STDIN から読み込みに失敗しました", ExitCode::ERROR);
            }
            return self::decodeJson($raw, 'STDIN');
        }

        // 優先度 3: --file=<path>
        if ($file !== '') {
            return self::loadFile($file);
        }

        throw new PayloadException(
            "--data、--file、または --file=（STDIN）のいずれかが必須です",
            ExitCode::USAGE
        );
    }

    /** @return array<string, mixed>
     * @throws PayloadException
     */
    private static function loadFile(string $path): array
    {
        if (is_link($path)) {
            throw new PayloadException("シンボリックリンクは許可されていません: " . basename($path), ExitCode::ERROR);
        }

        if (!is_readable($path)) {
            throw new PayloadException("ファイルは読み込み可能ではありません: " . basename($path), ExitCode::ERROR);
        }

        $isGzip = str_ends_with($path, '.gz') || self::isGzipMagic($path);

        if ($isGzip) {
            $compressed = file_get_contents($path);
            if ($compressed === false) {
                throw new PayloadException("ファイルの読み込みに失敗しました: " . basename($path), ExitCode::ERROR);
            }
            $raw = @gzdecode($compressed);
            if ($raw === false) {
                throw new PayloadException("invalid_gzip - ファイルの展開に失敗しました: " . basename($path), ExitCode::ERROR);
            }
            return self::decodeJson($raw, basename($path));
        }

        // プレーンファイル — サイズ制限なし（信頼できる境界）
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new PayloadException("ファイルの読み込みに失敗しました: " . basename($path), ExitCode::ERROR);
        }

        return self::decodeJson($raw, basename($path));
    }

    /**
     * gzip マジックバイト（\x1f\x8b）を拡張子に頼らずに検出
     */
    private static function isGzipMagic(string $path): bool
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return false;
        }
        $magic = fread($fh, 2);
        fclose($fh);
        return $magic === "\x1f\x8b";
    }

    /**
     * JSON デコード; エラー時に PayloadException を投げる
     * 成功時、生入力のバイト数と sha256 で $lastMeta を設定
     *
     * @return array<string, mixed>
     * @throws PayloadException
     */
    private static function decodeJson(string $raw, string $source): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new PayloadException(
                "{$source} からの無効な JSON: " . json_last_error_msg(),
                ExitCode::ERROR
            );
        }
        self::$lastMeta = [
            'bytes'  => strlen($raw),
            'sha256' => hash('sha256', $raw),
        ];
        return $decoded;
    }
}
