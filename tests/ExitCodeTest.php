<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tkmx\HfcmCli\Console\ExitCode;

class ExitCodeTest extends TestCase
{
    public function testConstants(): void
    {
        $this->assertSame(0, ExitCode::OK);
        $this->assertSame(1, ExitCode::ERROR);
        $this->assertSame(2, ExitCode::WP_LOAD_FAIL);
        $this->assertSame(3, ExitCode::PLUGIN_INACTIVE);
        $this->assertSame(4, ExitCode::FORBIDDEN);
        $this->assertSame(64, ExitCode::USAGE);
        $this->assertSame(70, ExitCode::INTERNAL);
        $this->assertSame(75, ExitCode::TEMPFAIL);
    }

    public function testAllConstantsAreUnique(): void
    {
        $codes = [
            ExitCode::OK,
            ExitCode::ERROR,
            ExitCode::WP_LOAD_FAIL,
            ExitCode::PLUGIN_INACTIVE,
            ExitCode::FORBIDDEN,
            ExitCode::USAGE,
            ExitCode::INTERNAL,
            ExitCode::TEMPFAIL,
        ];
        $this->assertSame(count($codes), count(array_unique($codes)));
    }
}
