<?php

namespace ClarionApp\LlmClient\Services;

/**
 * Sanitizes a third-party MCP server's own tool description before it is
 * cached or shown to a model: strips control characters, collapses runs
 * of whitespace, truncates to a configured bound, and prepends a
 * provenance prefix naming the server it came from -- so a model always
 * sees external-tool text as visibly external, the same descriptive-
 * provenance role CapabilityCatalogMerger::formatOffering() already plays
 * for search-result entries sourced from elsewhere in this codebase.
 */
class McpClientTextSanitizer
{
    public function sanitize(?string $description, string $serverName): string
    {
        $normalized = $this->normalize((string) $description);

        $maxLength = (int) config('llm-client.mcp_client.description_max_length', 500);
        if ($maxLength > 0 && mb_strlen($normalized) > $maxLength) {
            $normalized = mb_substr($normalized, 0, $maxLength);
        }

        $prefix = "[External tool via {$serverName}]";

        return $normalized === '' ? $prefix : "{$prefix} {$normalized}";
    }

    /**
     * Replaces every control character (including the tabs/newlines
     * runs of whitespace collapsing would otherwise have to treat
     * specially) with a plain space, then collapses every run of
     * whitespace down to exactly one space and trims the ends.
     */
    private function normalize(string $text): string
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
        $collapsed = preg_replace('/\s+/u', ' ', $stripped) ?? '';

        return trim($collapsed);
    }
}
