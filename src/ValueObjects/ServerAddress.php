<?php

namespace ClarionApp\LlmClient\ValueObjects;

use InvalidArgumentException;

/**
 * Immutable value object representing a normalized LLM server address.
 *
 * Normalizes arbitrary user input (bare hostnames, full endpoint URLs, etc.)
 * into a canonical base URL. Recognized API suffixes are stripped so the
 * resulting value is the server root (or a custom path prefix).
 */
final class ServerAddress
{
    /**
     * Recognized API endpoint suffixes, ordered longest-first so the
     * longest match wins when stripping.
     */
    private const API_SUFFIXES = [
        '/v1/chat/completions',
        '/chat/completions',
        '/v1/completions',
        '/completions',
        '/v1/messages',
        '/v1/embeddings',
        '/embeddings',
        '/v1/models',
        '/models',
        '/completion',
        '/v1/chat',
        '/chat',
        '/api/v1',
        '/v1',
    ];

    /**
     * The canonical base URL (scheme + host + optional custom path).
     */
    private readonly string $base;

    /**
     * The origin portion (scheme + host).
     */
    private readonly string $origin;

    /**
     * The path prefix (everything after the host, without trailing slash).
     * Empty string when the address is just scheme + host.
     */
    private readonly string $prefixPath;

    private function __construct(string $base, string $origin, string $prefixPath)
    {
        $this->base = $base;
        $this->origin = $origin;
        $this->prefixPath = $prefixPath;
    }

    /**
     * Parse and normalize a user-supplied server address string.
     *
     * @throws InvalidArgumentException If the input cannot be parsed as a URL with a host.
     */
    public static function fromInput(string $input): self
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Server address cannot be empty.');
        }

        // Quick rejection: if it doesn't look like a URL at all (no scheme,
        // no colon for host:port, no dot that could be a domain), reject early.
        if (!str_contains($trimmed, '://')
            && !str_contains($trimmed, ':')
            && !str_contains($trimmed, '.')
        ) {
            throw new InvalidArgumentException("Invalid server address: '{$trimmed}'");
        }

        return self::normalize($trimmed);
    }

    /**
     * Parse a value that is already stored (should be normalized).
     * Still validates that it is a valid URL.
     *
     * @throws InvalidArgumentException If the value is empty or unparseable.
     */
    public static function fromStored(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('Server address cannot be empty.');
        }

        $parsed = parse_url($value);
        if ($parsed === false || !isset($parsed['host']) || $parsed['host'] === '') {
            throw new InvalidArgumentException("Invalid server address: '{$value}'");
        }

        $scheme = ($parsed['scheme'] ?? 'https') . '://';
        $host = strtolower($parsed['host']);
        $port = $parsed['port'] ?? null;

        // Drop default ports.
        if ($port === 80 && strtolower($parsed['scheme'] ?? 'https') === 'http') {
            $port = null;
        }
        if ($port === 443 && strtolower($parsed['scheme'] ?? 'https') === 'https') {
            $port = null;
        }

        $origin = $scheme . $host . ($port !== null ? ':' . $port : '');

        $path = $parsed['path'] ?? '/';

        // Strip ONE recognized API suffix (longest match first).
        $path = self::stripOneSuffix($path);

        // Strip trailing slash.
        $path = rtrim($path, '/');

        return new self($origin . $path, $origin, $path);
    }

    /**
     * The origin (scheme + host, no path).
     */
    public function origin(): string
    {
        return $this->origin;
    }

    /**
     * The full base URL (origin + path prefix).
     * Equivalent to prefix().
     */
    public function base(): string
    {
        return $this->base;
    }

    /**
     * The full base URL (origin + path prefix).
     * Alias of base() for the endpoint resolver's "prefix" terminology.
     */
    public function prefix(): string
    {
        return $this->base;
    }

    /**
     * String representation — returns the canonical base URL.
     */
    public function __toString(): string
    {
        return $this->base;
    }

    /**
     * Core normalization algorithm.
     */
    private static function normalize(string $input): self
    {
        if ($input === '') {
            throw new InvalidArgumentException('Server address cannot be empty.');
        }

        // Step 2: Prepend scheme if missing.
        $url = $input;
        if (!str_contains($url, '://')) {
            // If it contains a colon (host:port), assume http.
            // Otherwise it's a bare hostname — assume https.
            if (str_contains($url, ':')) {
                $url = 'http://' . $url;
            } else {
                $url = 'https://' . $url;
            }
        }

        // Step 3: Parse and validate.
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host']) || $parsed['host'] === '') {
            throw new InvalidArgumentException("Invalid server address: '{$input}'");
        }

        // Step 4: Lowercase scheme and host, drop default port.
        $scheme = strtolower($parsed['scheme'] ?? 'https') . '://';
        $host = strtolower($parsed['host']);
        $port = $parsed['port'] ?? null;

        if ($port === 80 && $scheme === 'http://') {
            $port = null;
        }
        if ($port === 443 && $scheme === 'https://') {
            $port = null;
        }

        // Step 5: Discard query string, fragment, userinfo (already not in base_url).
        $path = $parsed['path'] ?? '/';

        // Step 6: Strip ONE recognized API suffix (longest match first).
        $normalizedPath = self::stripOneSuffix($path);

        // Step 7: Strip trailing slash.
        $normalizedPath = rtrim($normalizedPath, '/');

        $origin = $scheme . $host . ($port !== null ? ':' . $port : '');
        $base = $origin . $normalizedPath;

        return new self($base, $origin, $normalizedPath);
    }

    /**
     * Strip at most one recognized API suffix from the path.
     * Longest match first, so /v1/chat/completions is checked before /v1.
     */
    private static function stripOneSuffix(string $path): string
    {
        foreach (self::API_SUFFIXES as $suffix) {
            $suffixLen = strlen($suffix);
            $pathLen = strlen($path);

            if ($pathLen >= $suffixLen) {
                $ending = substr($path, -$suffixLen);
                if (strtolower($ending) === $suffix) {
                    $remainder = substr($path, 0, $pathLen - $suffixLen);
                    return $remainder;
                }
            }
        }

        return $path;
    }
}
