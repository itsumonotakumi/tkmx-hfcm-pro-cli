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
        $this->assertSame('[編集済]', $redacted['data']);
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

    public function testGetStringReturnsFallbackWhenOptionIsBoolFlag(): void
    {
        // --pretty is a flag (stored as true), getString should return the default.
        $args = new Args(['--pretty']);
        $this->assertSame('default', $args->getString('pretty', 'default'));
    }

    public function testGetBoolReturnsFalseForAbsentKey(): void
    {
        $args = new Args([]);
        $this->assertFalse($args->getBool('nonexistent'));
    }

    public function testShortOptionAtEndOfArgvTreatedAsFlag(): void
    {
        // -k with no following token (end of argv) — stored as true.
        $args = new Args(['-k']);
        $this->assertTrue($args->getBool('k'));
    }

    public function testLongOptionValueIsNextTokenEvenIfNumeric(): void
    {
        // --page 3 (space-separated, numeric value)
        $args = new Args(['--page', '3']);
        $this->assertSame(3, $args->getInt('page'));
    }

    public function testLongOptionFollowedByAnotherOptionIsFlag(): void
    {
        // --pretty --format=json: --pretty has no value, should be bool flag.
        $args = new Args(['--pretty', '--format=json']);
        $this->assertTrue($args->getBool('pretty'));
        $this->assertSame('json', $args->getString('format'));
    }

    public function testToRedactedArrayRedactsPassword(): void
    {
        $args     = new Args(['--password=secret123', '--format=json']);
        $redacted = $args->toRedactedArray();
        $this->assertSame('[編集済]', $redacted['password']);
        $this->assertSame('json', $redacted['format']);
    }

    public function testToRedactedArrayRedactsSecret(): void
    {
        $args     = new Args(['--secret=abc', '--out=file.json']);
        $redacted = $args->toRedactedArray();
        $this->assertSame('[編集済]', $redacted['secret']);
        $this->assertSame('[編集済]', $redacted['out']);
    }

    public function testToRedactedArrayRedactsToken(): void
    {
        $args     = new Args(['--token=xyz']);
        $redacted = $args->toRedactedArray();
        $this->assertSame('[編集済]', $redacted['token']);
    }

    public function testGetIntReturnsDefaultForNonNumericValue(): void
    {
        $args = new Args(['--page=notanumber']);
        $this->assertSame(0, $args->getInt('page'));
        $this->assertSame(5, $args->getInt('page', 5));
    }

    public function testMultipleParsedOptions(): void
    {
        $args = new Args(['--format=json', '--pretty', '--quiet', '--page=2']);
        $this->assertSame('json', $args->getString('format'));
        $this->assertTrue($args->getBool('pretty'));
        $this->assertTrue($args->getBool('quiet'));
        $this->assertSame(2, $args->getInt('page'));
    }

    public function testPositionalInterspersedWithOptions(): void
    {
        // Positionals are collected; options are parsed.
        $args = new Args(['snippets:delete', '--id=42', 'extra']);
        $this->assertSame('snippets:delete', $args->positional(0));
        $this->assertSame('extra', $args->positional(1));
        $this->assertSame('42', $args->getString('id'));
    }
}
