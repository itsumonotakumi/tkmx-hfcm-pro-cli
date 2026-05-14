<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Support;

use Tkmx\HfcmCli\Console\ExitCode;

/**
 * 読み込み/解析失敗時に PayloadLoader で投げられる
 * bin/hfcm でキャッチされ、適切な終了コードに変換される
 */
class PayloadException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $exitCode = ExitCode::ERROR,
    ) {
        // $code 引数として exitCode を渡し、PHPUnit の expectExceptionCode() が動作するようにする
        parent::__construct($message, $exitCode);
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }
}
