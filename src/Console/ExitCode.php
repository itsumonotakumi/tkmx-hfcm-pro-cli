<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Console;

class ExitCode
{
    public const OK           = 0;
    public const ERROR        = 1;
    public const WP_LOAD_FAIL = 2;
    public const PLUGIN_INACTIVE = 3;
    public const FORBIDDEN    = 4;
    public const USAGE        = 64;
    public const INTERNAL     = 70;
    public const TEMPFAIL     = 75;
}
