<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tkmx\HfcmCli\Console\Args;

class ArgsTest extends TestCase
{
    public function testLongOptionWithEquals(): void
    {
        $args = new Args(['--format=json', '--pretty']);
        $this->assertSame('json', $args->getString('format'));
        $this->assertTrue($args->getBool('pretty'));
    }

    public function testLongOptionSpaceSeparated(): void
    {
        $args = new Args(['--format', 'table']);
        $this->assertSame('table', $args->getString('format'));
    }

    public function testShortOption(): void
    {
        $args = new Args(['-f', 'data.json']);
        $this->assertSame('data.json', $args->getString('f'));
    }

    public function testPositionalArgs(): void
    {
        $args = new Args(['snippets:get', '42', '--pretty']);
        $this->assertSame('snippets:get', $args->positional(0));
        $this->assertSame('42', $args->positional(1));
        $this->assertNull($args->positional(2));
    }

    public function testGetInt(): void
    {
        $args = new Args(['--page=3']);
        $this->assertSame(3, $args->getInt('page'));
        $this->assertSame(0, $args->getInt('missing'));
        $this->assertSame(99, $args->getInt('missing', 99));
    }

    public function testGetDefault(): void
    {
        $args = new Args([]);
        $this->assertSame('default', $args->getString('key', 'default'));
        $this->assertNull($args->get('key'));
    }

    public function testHas(): void
    {
        $args = new Args(['--quiet']);
        $this->assertTrue($args->has('quiet'));
        $this->assertFalse($args->has('verbose'));
    }

    public function testToRedactedArray(): void
    {
        $args = new Args(['--data={"name":"test"}', '--format=json']);
        $redacted = $args->toRedactedArray();
        $this->assertSame('[REDACTED]', $redacted['data']);
        $this->assertSame('json', $redacted['format']);
    }

    public function testFlagValueIsTrue(): void
    {
        $args = new Args(['--verbose']);
        $this->assertTrue($args->getBool('verbose'));
        $this->assertFalse($args->getBool('quiet'));
    }

    public function testAllPositional(): void
    {
        $args = new Args(['foo', 'bar', '--flag']);
        $this->assertSame(['foo', 'bar'], $args->allPositional());
    }
}
