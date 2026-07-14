<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Events\ToolResultCondensed;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/**
 * Intercepts tool results and condenses oversized results before they enter agent context.
 *
 * Small results pass through unchanged. Large results are condensed using:
 * - Deterministic structure-aware reduction for JSON/structured data
 * - LLM-based summarization for prose/text (with truncation fallback)
 *
 * Full results are cached for on-demand retrieval via reference ID.
 */
class ToolResultCondenser
{
    /** @var array<string, mixed> */
    private array $config;

    /** Characters-per-token ratio (matching ContextWindowBudgeter convention). */
    private const CHARS_PER_TOKEN = 4;

    public function __construct(
        private ?StructureReducer $structureReducer = null,
        private ?LlmProvider $condensationProvider = null,
        private ?ProviderRegistry $providerRegistry = null,
        ?array $config = null
    ) {
        $this->config = $config ?? self::resolveConfig();
    }

    /**
     * Safely resolve the tool_result_condensation config block.
     */
    private static function resolveConfig(): array
    {
        try {
            return function_exists('config')
                ? config('llm-client.tool_result_condensation', [])
                : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Estimate token count from a string using the strlen-based estimator.
     * Matches the convention used by ContextWindowBudgeter (~4 chars per token).
     */
    public static function estimateTokens(string $content): int
    {
        return max(1, (int) ceil(strlen($content) / self::CHARS_PER_TOKEN));
    }

    /**
     * Generate a random 16-character hex reference ID.
     */
    private static function generateReferenceId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Cache key pattern: tool_result:{conversation_id}:{reference_id}.
     */
    private static function cacheKey(string $conversationId, string $referenceId): string
    {
        return "tool_result:{$conversationId}:{$referenceId}";
    }

    /**
     * Store the full (uncondensed) result in cache.
     */
    private function storeInCache(string $conversationId, string $referenceId, string $content): void
    {
        $ttlMinutes = (int) ($this->config['cache_ttl_minutes'] ?? 240);
        Cache::put(
            self::cacheKey($conversationId, $referenceId),
            $content,
            now()->addMinutes($ttlMinutes)
        );
    }

    /**
     * Retrieve the full result from cache by reference ID.
     *
     * @return string|null Full content or null if not found/expired.
     */
    public function get(string $conversationId, string $referenceId): ?string
    {
        return Cache::get(self::cacheKey($conversationId, $referenceId));
    }

    /**
     * Main condensation orchestrator.
     *
     * Evaluates the tool result size, applies condensation if needed,
     * stores the full result in cache, and dispatches events.
     *
     * @param string $conversationId Conversation UUID.
     * @param string $toolName Tool that produced the result.
     * @param string $content Raw tool result content.
     * @return array Result with 'content', 'condensed' flag, and optional metadata.
     */
    public function condense(string $conversationId, string $toolName, string $content): array
    {
        // Check master toggle — passthrough when disabled.
        if (!($this->config['enabled'] ?? true)) {
            return ['content' => $content, 'condensed' => false];
        }

        $threshold = (int) ($this->config['threshold_tokens'] ?? 2000);
        $tokenCount = self::estimateTokens($content);

        // Below threshold — passthrough.
        if ($tokenCount <= $threshold) {
            return ['content' => $content, 'condensed' => false];
        }

        // Binary/non-text detection: skip condensation for binary content.
        if ($this->isBinaryContent($content)) {
            return ['content' => $content, 'condensed' => false];
        }

        // Generate reference ID and store full content in cache.
        $referenceId = self::generateReferenceId();
        $this->storeInCache($conversationId, $referenceId, $content);

        // Detect content type and dispatch to appropriate condensation path.
        $decoded = json_decode($content, true);
        if (is_array($decoded) || is_object($decoded)) {
            // Structured data path.
            return $this->condenseStructured(
                $conversationId, $toolName, $content, $referenceId, $tokenCount
            );
        }

        // Prose/text path.
        return $this->condenseProse(
            $conversationId, $toolName, $content, $referenceId, $tokenCount
        );
    }

    /**
     * Detect binary/non-text content by checking for null bytes or high non-printable ratio.
     */
    private function isBinaryContent(string $content): bool
    {
        // Check for null bytes (strong indicator of binary data).
        if (str_contains($content, "\x00")) {
            return true;
        }

        // Check non-printable character ratio.
        $length = strlen($content);
        if ($length === 0) {
            return false;
        }

        // Count non-printable chars (excluding common whitespace like \t, \n, \r).
        $nonPrintable = 0;
        $sample = mb_strimwidth($content, 0, 4096, ''); // Sample first 4KB for performance.
        $sampleLength = strlen($sample);
        for ($i = 0; $i < $sampleLength; $i++) {
            $byte = ord($sample[$i]);
            if ($byte < 32 && $byte !== 9 && $byte !== 10 && $byte !== 13) {
                $nonPrintable++;
            } elseif ($byte > 126 && $byte < 160) {
                $nonPrintable++;
            }
        }

        // If more than 10% of sampled bytes are non-printable, treat as binary.
        return ($nonPrintable / $sampleLength) > 0.10;
    }

    /**
     * Condense structured (JSON) data using StructureReducer.
     */
    private function condenseStructured(
        string $conversationId,
        string $toolName,
        string $content,
        string $referenceId,
        int $originalTokens
    ): array {
        $maxCondensedTokens = (int) ($this->config['max_condensed_tokens'] ?? 500);
        $sampleItems = (int) ($this->config['sample_items'] ?? 5);

        $reducer = $this->structureReducer ?? new StructureReducer($this->config);
        $reduced = $reducer->reduce(
            json_decode($content, true),
            $maxCondensedTokens,
            $sampleItems
        );

        $condensedContent = json_encode($reduced, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $condensedTokens = self::estimateTokens($condensedContent);

        // Dispatch event.
        Event::dispatch(new ToolResultCondensed(
            $conversationId,
            $toolName,
            $referenceId,
            $originalTokens,
            $condensedTokens,
            max(0, $originalTokens - $condensedTokens),
            'deterministic',
            false
        ));

        return [
            'content' => $condensedContent,
            'condensed' => true,
            'reference_id' => $referenceId,
            'original_tokens' => $originalTokens,
            'condensed_tokens' => $condensedTokens,
            'method' => 'deterministic',
        ];
    }

    /**
     * Condense prose/text using LLM summarization with truncation fallback.
     */
    private function condenseProse(
        string $conversationId,
        string $toolName,
        string $content,
        string $referenceId,
        int $originalTokens
    ): array {
        $maxCondensedTokens = (int) ($this->config['max_condensed_tokens'] ?? 500);
        $timeoutSeconds = (int) ($this->config['summarization_timeout_seconds'] ?? 5);

        // Try LLM summarization first.
        $summarized = $this->summarizeWithLlm($content, $maxCondensedTokens, $timeoutSeconds);

        if ($summarized !== null) {
            $condensedTokens = self::estimateTokens($summarized);

            Event::dispatch(new ToolResultCondensed(
                $conversationId,
                $toolName,
                $referenceId,
                $originalTokens,
                $condensedTokens,
                max(0, $originalTokens - $condensedTokens),
                'llm',
                false
            ));

            return [
                'content' => $summarized,
                'condensed' => true,
                'reference_id' => $referenceId,
                'original_tokens' => $originalTokens,
                'condensed_tokens' => $condensedTokens,
                'method' => 'llm',
            ];
        }

        // Fallback: deterministic truncation.
        return $this->truncateFallback(
            $conversationId, $toolName, $content, $referenceId, $originalTokens
        );
    }

    /**
     * Summarize text content using the LLM condensation provider.
     *
     * @return string|null Summarized text or null on failure/timeout.
     */
    private function summarizeWithLlm(string $content, int $maxTokens, int $timeoutSeconds): ?string
    {
        $provider = $this->resolveCondensationProvider();
        if ($provider === null) {
            return null;
        }

        // Extract important values before summarization.
        $preserved = $this->extractPreservedValues($content);

        $prompt = "Summarize the following text in a concise manner. Preserve any error messages, identifiers, file paths, and important values.\n\n"
            . "Text to summarize:\n\n" . $content;

        try {
            // Use a short timeout for tool result summarization.
            $previousTimeout = ini_get('default_socket_timeout');
            ini_set('default_socket_timeout', (string) $timeoutSeconds);

            $result = $provider->chat(
                [['role' => 'user', 'content' => $prompt]],
                [],
                ['model' => null, 'temperature' => 0.3]
            );

            ini_set('default_socket_timeout', $previousTimeout);

            $choice = $result['choices'][0] ?? null;
            $text = $choice['message']['content'] ?? null;

            if ($text !== null && $text !== '') {
                // Append preserved values.
                if ($preserved !== '') {
                    $text .= "\n\nPreserved details:\n" . $preserved;
                }

                // Enforce max condensed tokens cap.
                $textTokens = self::estimateTokens($text);
                if ($textTokens > $maxTokens) {
                    $maxChars = $maxTokens * self::CHARS_PER_TOKEN;
                    $text = mb_strimwidth($text, 0, $maxChars, '...');
                }

                return $text;
            }

            return null;
        } catch (\Throwable) {
            // On any error (timeout, network, parsing), return null for truncation fallback.
            return null;
        }
    }

    /**
     * Extract important values from prose content for preservation.
     */
    private function extractPreservedValues(string $content): string
    {
        $preserved = [];

        // Extract error messages.
        if (preg_match_all('/(?:Error|Exception|Warning|Fatal|Notice)[:\s]+(.+?)(?:\n|$)/i', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $trimmed = trim($match);
                if ($trimmed !== '' && strlen($trimmed) < 500) {
                    $preserved[] = "Error: {$trimmed}";
                }
            }
        }

        // Extract file paths.
        if (preg_match_all('/(?:\/[^\/\s]+\){2,}|(?:[A-Z]:\\\/[^\'\s]+)/', $content, $matches)) {
            $paths = array_unique(array_map('trim', $matches[0]));
            foreach (array_slice($paths, 0, 5) as $path) {
                $preserved[] = "Path: {$path}";
            }
        }

        // Extract UUIDs.
        if (preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $content, $matches)) {
            $uuids = array_unique($matches[0]);
            foreach (array_slice($uuids, 0, 5) as $uuid) {
                $preserved[] = "ID: {$uuid}";
            }
        }

        return implode("\n", $preserved);
    }

    /**
     * Deterministic truncation fallback when LLM summarization fails.
     */
    private function truncateFallback(
        string $conversationId,
        string $toolName,
        string $content,
        string $referenceId,
        int $originalTokens
    ): array {
        $maxCondensedTokens = (int) ($this->config['max_condensed_tokens'] ?? 500);
        $maxChars = $maxCondensedTokens * self::CHARS_PER_TOKEN;

        $truncated = mb_strimwidth($content, 0, $maxChars - 80, '');
        $suffix = "[...condensed: original result was {$originalTokens} tokens, truncated. Reference: {$referenceId}]";
        $condensedContent = $truncated . $suffix;

        $condensedTokens = self::estimateTokens($condensedContent);

        Event::dispatch(new ToolResultCondensed(
            $conversationId,
            $toolName,
            $referenceId,
            $originalTokens,
            $condensedTokens,
            max(0, $originalTokens - $condensedTokens),
            'truncation',
            true
        ));

        return [
            'content' => $condensedContent,
            'condensed' => true,
            'reference_id' => $referenceId,
            'original_tokens' => $originalTokens,
            'condensed_tokens' => $condensedTokens,
            'method' => 'truncation',
        ];
    }

    /**
     * Resolve the condensation provider (same as ConversationCondenser).
     */
    private function resolveCondensationProvider(): ?LlmProvider
    {
        if ($this->condensationProvider !== null) {
            return $this->condensationProvider;
        }

        if ($this->providerRegistry !== null) {
            // Try to resolve from config.
            $providerType = $this->config['provider'] ?? null;
            if ($providerType) {
                try {
                    $type = ProviderType::from($providerType);
                    $server = \ClarionApp\LlmClient\Models\Server::first();
                    if ($server) {
                        return $this->providerRegistry->resolveByType($type, $server);
                    }
                } catch (\Throwable) {
                    // Fall through to default.
                }
            }

            // Default: resolve first available provider.
            try {
                $server = \ClarionApp\LlmClient\Models\Server::first();
                if ($server) {
                    return $this->providerRegistry->resolveByType(
                        $server->provider_type,
                        $server
                    );
                }
            } catch (\Throwable) {
                // No provider available.
            }
        }

        return null;
    }
}
