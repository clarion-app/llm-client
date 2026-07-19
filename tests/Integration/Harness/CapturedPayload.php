<?php

namespace Tests\Integration\Harness;

use ClarionApp\HttpQueue\HttpRequest;
use Psr\Http\Message\RequestInterface;

/**
 * Normalized payload value object for LLM requests.
 *
 * Extracts the essential fields from either a Guzzle PSR-7 request
 * or an http-queue HttpRequest into a single shape so payload assertions
 * are written once across both sync and stream entry paths.
 */
class CapturedPayload
{
    public function __construct(
        public array $messages,
        public array $tools,
        public ?string $system,
        public ?string $model,
        public string $kind,
        public string $source,
    ) {
    }

    /**
     * Extract from a Guzzle PSR-7 request.
     *
     * @param RequestInterface $request The outgoing HTTP request (sync or stream).
     * @param string $kind 'chat' or 'embedding'.
     */
    public static function fromGuzzleRequest(RequestInterface $request, string $kind = 'chat'): self
    {
        $bodyContent = $request->getBody()->getContents();
        return self::fromGuzzleBody($bodyContent, $kind);
    }

    /**
     * Extract from a raw JSON body string (when the stream may already be consumed).
     *
     * @param string $bodyContent Raw JSON body string.
     * @param string $kind 'chat' or 'embedding'.
     */
    public static function fromGuzzleBody(string $bodyContent, string $kind = 'chat'): self
    {
        $body = json_decode($bodyContent, true) ?: [];

        // Determine source from stream flag in the body
        $source = ($body['stream'] ?? false) ? 'stream' : 'sync';

        return new self(
            messages: (array) ($body['messages'] ?? []),
            tools: (array) ($body['tools'] ?? []),
            system: $body['system'] ?? null,
            model: $body['model'] ?? null,
            kind: $kind,
            source: $source,
        );
    }

    /**
     * Extract from an http-queue HttpRequest.
     *
     * These are only produced by dispatched SendHttpStreamRequest jobs, so the
     * source is always the streaming entry path.
     *
     * @param HttpRequest $request The outgoing HTTP request.
     */
    public static function fromHttpRequest(HttpRequest $request): self
    {
        $body = $request->body;

        return new self(
            messages: is_object($body) && isset($body->messages)
                ? (array) $body->messages
                : [],
            tools: is_object($body) && isset($body->tools)
                ? (array) $body->tools
                : [],
            system: is_object($body) && isset($body->system)
                ? $body->system
                : null,
            model: is_object($body) && isset($body->model)
                ? $body->model
                : null,
            kind: 'chat',
            source: 'stream',
        );
    }

    /**
     * Check if any message content contains the needle.
     */
    public function containsText(string $needle): bool
    {
        foreach ($this->messages as $message) {
            $content = $message['content'] ?? '';
            if (is_string($content) && str_contains($content, $needle)) {
                return true;
            }
            // Handle array content (e.g., multimodal messages)
            if (is_array($content)) {
                foreach ($content as $part) {
                    $text = $part['text'] ?? $part['content'] ?? '';
                    if (is_string($text) && str_contains($text, $needle)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Check if the system string contains the needle.
     */
    public function systemContains(string $needle): bool
    {
        if ($this->system === null) {
            return false;
        }
        return str_contains($this->system, $needle);
    }

    /**
     * Return the count of messages.
     */
    public function messageCount(): int
    {
        return count($this->messages);
    }

    /**
     * Apply an estimator callable to each message content and sum the results.
     *
     * @param callable(string): int $estimator Function that takes content string and returns token estimate.
     */
    public function estimatedTokens(callable $estimator): int
    {
        $total = 0;
        foreach ($this->messages as $message) {
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $total += $estimator($content);
            } elseif (is_array($content)) {
                foreach ($content as $part) {
                    $text = $part['text'] ?? $part['content'] ?? '';
                    if (is_string($text)) {
                        $total += $estimator($text);
                    }
                }
            }
        }
        return $total;
    }

    /**
     * Return the index of the first message containing the needle, or -1 if not found.
     *
     * Useful for relative ordering assertions (e.g., "billing" appears before "timezones").
     */
    public function indexOfText(string $needle): int
    {
        foreach ($this->messages as $index => $message) {
            $content = $message['content'] ?? '';
            if (is_string($content) && str_contains($content, $needle)) {
                return $index;
            }
            if (is_array($content)) {
                foreach ($content as $part) {
                    $text = $part['text'] ?? $part['content'] ?? '';
                    if (is_string($text) && str_contains($text, $needle)) {
                        return $index;
                    }
                }
            }
        }
        return -1;
    }
}
