<?php

namespace ClarionApp\LlmClient\Services;

use Illuminate\Support\Facades\Log;

class UrlValidator
{
    /**
     * Validate a URL for SSRF protection.
     *
     * @param string $url
     * @return array{valid: bool, reason?: string}
     */
    public static function validate(string $url): array
    {
        // Check scheme
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return ['valid' => false, 'reason' => 'Malformed URL'];
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['valid' => false, 'reason' => 'Only HTTP and HTTPS schemes are allowed'];
        }

        // Resolve hostname to IP
        $host = $parsed['host'];
        $ip = gethostbyname($host);

        // gethostbyname returns the hostname if resolution fails
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            return ['valid' => false, 'reason' => 'Unable to resolve hostname'];
        }

        // If an IP was provided directly, use it
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        }

        // Check if IP is in a private/reserved range
        if (self::isPrivateOrReservedIp($ip)) {
            return ['valid' => false, 'reason' => 'URL targets a private or reserved IP range'];
        }

        return ['valid' => true];
    }

    /**
     * Validate a redirect target URL (re-validation per hop).
     *
     * @param string $url
     * @param int $currentHop
     * @return array{valid: bool, reason?: string}
     */
    public static function validateRedirect(string $url, int $currentHop = 0): array
    {
        $maxRedirects = config('llm-client.ssrf.max_redirects', 5);

        if ($currentHop >= $maxRedirects) {
            return ['valid' => false, 'reason' => 'Maximum redirect hops exceeded'];
        }

        return self::validate($url);
    }

    /**
     * Check if an IP address falls within private or reserved ranges.
     */
    public static function isPrivateOrReservedIp(string $ip): bool
    {
        // Loopback (127.0.0.0/8)
        if (str_starts_with($ip, '127.')) {
            return true;
        }

        // RFC 1918 private ranges
        // 10.0.0.0/8
        if (str_starts_with($ip, '10.')) {
            return true;
        }

        // 172.16.0.0/12
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip)) {
            return true;
        }

        // 192.168.0.0/16
        if (str_starts_with($ip, '192.168.')) {
            return true;
        }

        // Link-local (169.254.0.0/16)
        if (str_starts_with($ip, '169.254.')) {
            return true;
        }

        // RFC 5737 documentation ranges
        // 192.0.2.0/24
        if (str_starts_with($ip, '192.0.2.')) {
            return true;
        }
        // 198.51.100.0/24
        if (str_starts_with($ip, '198.51.100.')) {
            return true;
        }
        // 203.0.113.0/24
        if (str_starts_with($ip, '203.0.113.')) {
            return true;
        }

        // 0.0.0.0
        if ($ip === '0.0.0.0') {
            return true;
        }

        // IPv6 loopback
        if ($ip === '::1') {
            return true;
        }

        return false;
    }
}
