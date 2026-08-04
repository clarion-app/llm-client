<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\ValueObjects\ServerAddress;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ServerAddress value object.
 *
 * Tests the normalization table (18 rows) plus invariant properties.
 */
class ServerAddressTest extends TestCase
{
    // ─── Normalization table ───

    /**
     * @return iterable<array{input: string, expected: string|null}>
     */
    public static function normalizationCases(): iterable
    {
        yield 'http base' => ['http://localhost:8081', 'http://localhost:8081'];
        yield 'http base trailing slash' => ['http://localhost:8081/', 'http://localhost:8081'];
        yield 'host:port no scheme' => ['localhost:8081', 'http://localhost:8081'];
        yield 'whitespace trimmed' => ['  http://localhost:8081  ', 'http://localhost:8081'];
        yield 'bare hostname gets https' => ['api.example.com', 'https://api.example.com'];
        yield 'strip /v1/chat/completions' => ['http://localhost:8081/v1/chat/completions', 'http://localhost:8081'];
        yield 'strip /v1' => ['http://localhost:8081/v1', 'http://localhost:8081'];
        yield 'strip /v1/models' => ['http://localhost:8081/v1/models', 'http://localhost:8081'];
        yield 'strip anthropic suffix' => ['https://api.anthropic.com/v1/messages', 'https://api.anthropic.com'];
        yield 'custom path preserved' => ['https://host/llm', 'https://host/llm'];
        yield 'custom path with api suffix stripped' => ['https://host/llm/v1/chat/completions', 'https://host/llm'];
        yield 'custom path trailing slash stripped' => ['https://host/llm/', 'https://host/llm'];
        yield 'http default port dropped' => ['http://host:80', 'http://host'];
        yield 'https default port dropped' => ['https://host:443/v1', 'https://host'];
        yield 'query and fragment stripped' => ['http://localhost:8081/v1/chat/completions?x=1#f', 'http://localhost:8081'];
        yield 'case normalized' => ['HTTP://LocalHost:8081', 'http://localhost:8081'];
        yield 'empty string throws' => ['', null];
        yield 'not a url throws' => ['not a url', null];
    }

    #[Test]
    #[DataProvider('normalizationCases')]
    public function normalizesInput(string $input, ?string $expected): void
    {
        if ($expected === null) {
            $this->expectException(InvalidArgumentException::class);
            ServerAddress::fromInput($input);
            return;
        }

        $address = ServerAddress::fromInput($input);
        $this->assertSame($expected, $address->base());
    }

    // ─── fromStored() ───

    #[Test]
    public function fromStored_returns_valid_address(): void
    {
        $address = ServerAddress::fromStored('http://localhost:8081');
        $this->assertSame('http://localhost:8081', $address->base());
    }

    #[Test]
    public function fromStored_throws_on_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ServerAddress::fromStored('');
    }

    // ─── origin() and prefix() ───

    #[Test]
    public function origin_returns_scheme_and_host(): void
    {
        $address = ServerAddress::fromInput('http://localhost:8081/v1/chat/completions');
        $this->assertSame('http://localhost:8081', $address->origin());
    }

    #[Test]
    public function prefix_returns_full_base(): void
    {
        $address = ServerAddress::fromInput('https://host/llm/v1/chat/completions');
        $this->assertSame('https://host/llm', $address->prefix());
    }

    #[Test]
    public function base_equals_prefix_when_no_customPath(): void
    {
        $address = ServerAddress::fromInput('http://localhost:8081');
        $this->assertSame($address->base(), $address->prefix());
    }

    // ─── __toString() ───

    #[Test]
    public function toString_returns_base(): void
    {
        $address = ServerAddress::fromInput('http://localhost:8081');
        $this->assertSame('http://localhost:8081', (string) $address);
    }

    // ─── Invariants ───

    #[Test]
    #[DataProvider('normalizationCases')]
    public function idempotent_for_valid_inputs(string $input, ?string $expected): void
    {
        // Skip invalid inputs — they throw on first call.
        if ($expected === null) {
            self::markTestSkipped('Input is invalid; idempotency only applies to valid addresses.');
        }

        $first = ServerAddress::fromInput($input);
        $second = ServerAddress::fromInput((string) $first);
        $this->assertSame($first->base(), $second->base());
    }

    #[Test]
    #[DataProvider('normalizationCases')]
    public function baseNeverEndsWithSlash(string $input, ?string $expected): void
    {
        if ($expected === null) {
            self::markTestSkipped('Input is invalid.');
        }

        $address = ServerAddress::fromInput($input);
        $base = $address->base();
        $this->assertMatchesRegularExpression('/[^\/]$/', $base, "base() must not end with /: {$base}");
    }

    #[Test]
    #[DataProvider('normalizationCases')]
    public function baseAlwaysContainsSchemeSeparator(string $input, ?string $expected): void
    {
        if ($expected === null) {
            self::markTestSkipped('Input is invalid.');
        }

        $address = ServerAddress::fromInput($input);
        $this->assertStringContainsString(
            '://',
            $address->base(),
            'base() must always contain ://'
        );
    }

    #[Test]
    public function onlyOneSuffixStripped(): void
    {
        // https://host/v1/v1 → strip one /v1 → https://host/v1
        $address = ServerAddress::fromInput('https://host/v1/v1');
        $this->assertSame('https://host/v1', $address->base());
    }
}
