<?php

namespace Tests\Integration\Harness;

/**
 * T020: Wire-shaped response builders per lane.
 *
 * Reuses ResponseScript's existing wire shapes so a rule-produced response
 * is indistinguishable from an ordered-step one.
 */
class Responses
{
    /**
     * Build a condensation summary response.
     *
     * @param string $summary The condensed text.
     * @return array<string, mixed>
     */
    public static function condensationSummary(string $summary = 'Condensed summary of this segment.'): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $summary,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
    }

    /**
     * Build an episodic summary response.
     *
     * @param string $summary The episodic memory summary.
     * @return array<string, mixed>
     */
    public static function summary(string $summary = 'Episodic summary of this conversation.'): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $summary,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
    }

    /**
     * Build an episodic summary response whose content is valid JSON.
     *
     * summary() above produces plain text, which is what the agent_turn and
     * condensation lanes expect (the product only reads $content there).
     * GenerateEpisodicMemoryJob's summarizer call is different: it requests
     * response_format=json and does json_decode($content, true) expecting a
     * {"summary": ..., "topics": [...]} shape (src/Jobs/GenerateEpisodicMemoryJob.php
     * ::summarize()) — plain text fails that decode and the job reports
     * "Summarization failed" instead of storing a record. This builder keeps
     * the EpisodicSummary lane's response wire-shaped correctly without every
     * scenario having to know the job's parsing contract (Story 4, 060).
     *
     * @param string $summary The summary text (never asserted on by scenarios — S8/FR-008b).
     * @param list<string> $topics Topic tags.
     * @return array<string, mixed>
     */
    public static function episodicSummary(string $summary = 'Captured summary of this conversation.', array $topics = ['general']): array
    {
        return self::summary(json_encode(['summary' => $summary, 'topics' => $topics]));
    }

    /**
     * Build a tool request response.
     *
     * @param string $name Function name.
     * @param array<string, mixed> $arguments Function arguments.
     * @return array<string, mixed>
     */
    public static function toolRequest(string $name, array $arguments = []): array
    {
        return [
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
    }

    /**
     * Build a final answer response.
     *
     * @param string $content The assistant's text.
     * @return array<string, mixed>
     */
    public static function finalAnswer(string $content): array
    {
        return [
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
    }

    /**
     * Build an embedding response.
     *
     * @param list<string> $inputs Input texts.
     * @return array<string, mixed>
     */
    public static function embedding(array $inputs = ['test']): array
    {
        $embedder = new DeterministicEmbedder();
        $data = [];
        foreach ($inputs as $index => $input) {
            $data[] = [
                'embedding' => $embedder->embed($input),
                'index' => $index,
            ];
        }

        return [
            'data' => $data,
            'usage' => [
                'prompt_tokens' => 0,
                'total_tokens' => 0,
            ],
        ];
    }
}
