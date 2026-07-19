<?php

namespace Tests\Integration\Harness;

use RuntimeException;

/**
 * Ordered list of response steps with a cursor for deterministic test mocking.
 *
 * Steps produce provider-shaped wire responses (OpenAI format) so the real
 * provider parsing code runs — never LlmProvider::chat() return values.
 */
class ResponseScript
{
    /** @var list<array<string, mixed>> */
    public array $steps = [];

    /** @var int Current position in the steps list. */
    public int $cursor = 0;

    /**
     * Start a new script.
     *
     * Scenarios read as one declarative chain:
     *   ResponseScript::make()->toolRequest(...)->finalAnswer(...)
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * Add a tool request step (OpenAI chat completion format).
     *
     * @param string $name Function name to call.
     * @param array<string, mixed> $arguments Function arguments.
     */
    public function toolRequest(string $name, array $arguments): self
    {
        $this->steps[] = [
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'tool_calls' => [
                            [
                                'id' => 'call_' . bin2hex(random_bytes(8)),
                                'type' => 'function',
                                'function' => [
                                    'name' => $name,
                                    'arguments' => json_encode($arguments),
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
        ];
        return $this;
    }

    /**
     * Add a final answer step (OpenAI chat completion format).
     *
     * @param string $content The assistant's text response.
     */
    public function finalAnswer(string $content): self
    {
        $this->steps[] = [
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
        return $this;
    }

    /**
     * Add an embedding response step (OpenAI embeddings format).
     *
     * @param list<string> $inputs The input texts being embedded.
     */
    public function embedding(array $inputs): self
    {
        $embedder = new DeterministicEmbedder();
        $data = [];
        foreach ($inputs as $index => $input) {
            // Same content-hash vectors the transport serves, so a scripted
            // embedding step and a transport-served one are indistinguishable.
            $data[] = [
                'embedding' => $embedder->embed($input),
                'index' => $index,
            ];
        }

        $this->steps[] = [
            'data' => $data,
            'usage' => [
                'prompt_tokens' => 0,
                'total_tokens' => 0,
            ],
        ];
        return $this;
    }

    /**
     * Add usage info to the last step.
     *
     * @param int $in Prompt tokens.
     * @param int $out Completion tokens.
     */
    public function usage(int $in, int $out): self
    {
        if ($this->steps !== []) {
            $lastIndex = count($this->steps) - 1;
            $this->steps[$lastIndex]['usage'] = [
                'prompt_tokens' => $in,
                'completion_tokens' => $out,
                'total_tokens' => $in + $out,
            ];
        }
        return $this;
    }

    /**
     * Serve the next step and advance the cursor.
     *
     * If the cursor is past the end, throws a RuntimeException with the
     * request info rendered (never empty, default, or repeated).
     *
     * @param array<string, mixed> $requestInfo Context about the incoming request for error messages.
     * @return array<string, mixed> The next response step.
     */
    public function serve(array $requestInfo = []): array
    {
        if ($this->cursor >= count($this->steps)) {
            throw $this->buildExhaustionError($requestInfo);
        }

        return $this->steps[$this->cursor++];
    }

    /**
     * Check if there are unconsumed steps remaining.
     */
    public function hasUnconsumedSteps(): bool
    {
        return $this->cursor < count($this->steps);
    }

    /**
     * Count of remaining (unconsumed) steps.
     */
    public function unconsumedSteps(): int
    {
        return max(0, count($this->steps) - $this->cursor);
    }

    /**
     * Build a descriptive RuntimeException when the script is exhausted.
     *
     * @param array<string, mixed> $requestInfo Context about the request that triggered the error.
     */
    private function buildExhaustionError(array $requestInfo): RuntimeException
    {
        $parts = [
            'Response script exhausted — no more steps to serve.',
        ];

        if (!empty($requestInfo)) {
            $parts[] = 'Request context:';
            if (isset($requestInfo['message_count'])) {
                $parts[] = '  message_count: ' . $requestInfo['message_count'];
            }
            if (isset($requestInfo['entry_path'])) {
                $parts[] = '  entry_path: ' . $requestInfo['entry_path'];
            }
            if (isset($requestInfo['iteration'])) {
                $parts[] = '  iteration: ' . $requestInfo['iteration'];
            }
            if (isset($requestInfo['tool_names'])) {
                $parts[] = '  tool_names: ' . implode(', ', (array) $requestInfo['tool_names']);
            }
        }

        $parts[] = 'Scripted steps: ' . count($this->steps) . ' (all consumed).';

        return new RuntimeException(implode("\n", $parts));
    }
}
