<?php

namespace ClarionApp\LlmClient\Services;

use Illuminate\Support\Facades\Log;

class ContentSanitizer
{
    /** Truncation marker appended when content exceeds the byte cap. */
    private const TRUNCATION_MARKER = "\n\n[TRUNCATED: original content exceeded cap]";

    /** Precompiled regex patterns (built once from config). */
    private array $patterns = [];

    /** Byte cap for truncation. */
    private int $cap;

    public function __construct()
    {
        $this->cap = (int) config('llm-client.run_trace.action_content_cap_bytes', 16384);
        $this->buildPatterns();
    }

    /**
     * Apply redaction patterns to content string.
     * Returns the sanitized string (may be identical if no patterns match).
     * Never throws — returns input unchanged on regex error.
     */
    public function sanitize(string $content): string
    {
        if (empty($this->patterns)) {
            return $content;
        }

        try {
            return preg_replace($this->patterns['searches'], $this->patterns['replacements'], $content);
        } catch (\Throwable $e) {
            Log::warning('ContentSanitizer: regex error during sanitize', [
                'error' => $e->getMessage(),
            ]);
            return $content;
        }
    }

    /**
     * Truncate content to the configured byte cap.
     * Appends a truncation marker if content was truncated.
     * Returns the truncated string.
     */
    public function truncate(string $content): string
    {
        $byteLength = strlen($content);

        if ($byteLength <= $this->cap) {
            return $content;
        }

        $markerBytes = strlen(self::TRUNCATION_MARKER);
        $availableBytes = max(0, $this->cap - $markerBytes);

        // Truncate at byte boundary to avoid splitting multi-byte characters.
        $truncated = mb_strcut($content, 0, $availableBytes, 'UTF-8');

        return $truncated . self::TRUNCATION_MARKER;
    }

    /**
     * Combined: sanitize then truncate. Used by closeAction().
     * Order matters: redact before truncate (contract S4).
     */
    public function prepare(string $content): string
    {
        return $this->truncate($this->sanitize($content));
    }

    /**
     * Build regex patterns from config. Patterns are compiled once at construction.
     */
    private function buildPatterns(): void
    {
        $config = config('llm-client.run_trace.redaction_patterns', []);
        $searches = [];
        $replacements = [];

        // Header redaction: "header_name": "value" → "header_name": "[REDACTED]"
        foreach (($config['headers'] ?? []) as $header) {
            $pattern = '"(' . preg_quote($header, '/') . ')":\s*"([^"]*)"';
            $searches[] = '/' . $pattern . '/i';
            $replacements[] = '"$1": "[REDACTED]"';
        }

        // Bearer tokens: Bearer <token> → Bearer [REDACTED]
        $searches[] = '/Bearer\s+[a-zA-Z0-9\-._~+\/]+=*/';
        $replacements[] = 'Bearer [REDACTED]';

        // API key prefixes: sk-<long>, ghp_<long>, etc.
        foreach (($config['token_prefixes'] ?? []) as $prefix) {
            $escapedPrefix = preg_quote($prefix, '/');
            $searches[] = '/' . $escapedPrefix . '[a-zA-Z0-9]{20,}/';
            $replacements[] = $prefix . '[REDACTED]';
        }

        // JSON field redaction: "field_name": "value" → "field_name": "[REDACTED]"
        foreach (($config['json_fields'] ?? []) as $field) {
            $pattern = '"(' . preg_quote($field, '/') . '"):\s*"([^"]*)"';
            $searches[] = '/' . $pattern . '/i';
            $replacements[] = '"$1": "[REDACTED]"';
        }

        // URL query parameter redaction: ?param=value or &param=value
        foreach (($config['url_params'] ?? []) as $param) {
            $pattern = '[?&](' . preg_quote($param, '/') . ')=[^&]*';
            $searches[] = '/' . $pattern . '/i';
            $replacements[] = '$1=[REDACTED]';
        }

        $this->patterns = [
            'searches' => $searches,
            'replacements' => $replacements,
        ];
    }
}
