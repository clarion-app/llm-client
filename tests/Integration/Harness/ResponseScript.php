<?php

namespace Tests\Integration\Harness;

use RuntimeException;

/**
 * Ordered list of response steps with a cursor for deterministic test mocking.
 *
 * Steps produce provider-shaped wire responses (OpenAI format) so the real
 * provider parsing code runs — never LlmProvider::chat() return values.
 *
 * Extended with lane-scoped ordered queues (S5/S6): toolRequest/finalAnswer
 * fill the agent_turn lane; serveFor() evaluates rules then ordered steps per lane.
 */
class ResponseScript
{
    /** @var list<array<string, mixed>> Legacy steps list (agent_turn lane). */
    public array $steps = [];

    /** @var int Current position in the steps list. */
    public int $cursor = 0;

    /** @var array<string, list<array<string, mixed>>> Lane-scoped ordered step queues (keyed by lane value). */
    private array $laneQueues = [];

    /** @var array<string, int> Cursor per lane (keyed by lane value). */
    private array $laneCursors = [];

    /** @var list<LaneRule> Rules evaluated before ordered steps (S2). */
    private array $rules = [];

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
     * S5: This delegates to the agent_turn lane, keeping backward compat.
     * If the cursor is past the end, throws a RuntimeException with the
     * request info rendered (never empty, default, or repeated).
     *
     * @param array<string, mixed> $requestInfo Context about the incoming request for error messages.
     * @return array<string, mixed> The next response step.
     */
    public function serve(array $requestInfo = []): array
    {
        // S5: serve() delegates to agent_turn lane
        if ($this->cursor >= count($this->steps)) {
            throw $this->buildExhaustionError($requestInfo);
        }

        return $this->steps[$this->cursor++];
    }

    /**
     * Serve the next step for a specific lane (S2).
     *
     * Evaluates rules for that lane first, then falls through to the
     * lane's ordered step queue. On no match, fails loudly (S4).
     *
     * @param RequestLane $lane The lane to serve for.
     * @param CapturedPayload $payload The captured request payload.
     * @param int $turn The current 1-based turn number.
     * @return array<string, mixed> The response body.
     */
    public function serveFor(RequestLane $lane, CapturedPayload $payload, int $turn): array
    {
        // S2: Evaluate rules for this lane first
        foreach ($this->rules as $rule) {
            if ($rule->lane === $lane && $rule->matches($payload, $turn)) {
                $this->recordRuleFired($turn, $rule->label);
                return $rule->respond($payload, $turn);
            }
        }

        // Fall through to ordered steps for this lane
        if ($lane === RequestLane::AgentTurn) {
            // S5: agent_turn uses the legacy steps array
            if ($this->cursor >= count($this->steps)) {
                throw $this->buildLaneExhaustionError($lane, $payload, $turn);
            }
            return $this->steps[$this->cursor++];
        }

        // Other lanes use lane-scoped queues
        $laneKey = $lane->value;
        $queue = $this->laneQueues[$laneKey] ?? [];
        $cursor = $this->laneCursors[$laneKey] ?? 0;

        if ($cursor >= count($queue)) {
            throw $this->buildLaneExhaustionError($lane, $payload, $turn);
        }

        $this->laneCursors[$laneKey] = $cursor + 1;
        return $queue[$cursor];
    }

    /** @var array<int, array<string>> Map of turn number to rule labels that fired. */
    private array $rulesFiredByTurn = [];

    /**
     * Add a rule to be evaluated before ordered steps (S2).
     */
    public function addRule(LaneRule $rule): self
    {
        $this->rules[] = $rule;
        return $this;
    }

    /**
     * Get the rule labels that fired for a specific turn.
     *
     * @param int $turn The 1-based turn number.
     * @return array<string> List of rule labels that fired.
     */
    public function getRulesFiredForTurn(int $turn): array
    {
        return $this->rulesFiredByTurn[$turn] ?? [];
    }

    /**
     * Record that a rule fired for a specific turn.
     *
     * @param int $turn The 1-based turn number.
     * @param string $label The rule label.
     */
    public function recordRuleFired(int $turn, string $label): void
    {
        if (!array_key_exists($turn, $this->rulesFiredByTurn)) {
            $this->rulesFiredByTurn[$turn] = [];
        }
        $this->rulesFiredByTurn[$turn][] = $label;
    }

    /**
     * Reset the rules fired tracking.
     */
    public function resetRulesFired(): void
    {
        $this->rulesFiredByTurn = [];
    }

    /**
     * Push a step onto a specific lane's queue.
     *
     * @param RequestLane $lane The target lane.
     * @param array<string, mixed> $step The wire-shaped response.
     */
    public function pushStep(RequestLane $lane, array $step): self
    {
        if (!array_key_exists($lane->value, $this->laneQueues)) {
            $this->laneQueues[$lane->value] = [];
        }
        $this->laneQueues[$lane->value][] = $step;
        return $this;
    }

    /**
     * Check if there are unconsumed steps remaining on any lane (S6).
     *
     * Aggregates leftovers across every lane so the existing 053 tearDown
     * check catches a leftover on any lane and names it.
     */
    public function hasUnconsumedSteps(): bool
    {
        // Check legacy agent_turn steps
        if ($this->cursor < count($this->steps)) {
            return true;
        }

        // Check lane-scoped queues
        foreach ($this->laneQueues as $laneKey => $queue) {
            $cursor = $this->laneCursors[$laneKey] ?? 0;
            if ($cursor < count($queue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count of remaining (unconsumed) steps across all lanes (S6).
     */
    public function unconsumedSteps(): int
    {
        $total = max(0, count($this->steps) - $this->cursor);

        foreach ($this->laneQueues as $laneKey => $queue) {
            $cursor = $this->laneCursors[$laneKey] ?? 0;
            $total += max(0, count($queue) - $cursor);
        }

        return $total;
    }

    /**
     * Get unconsumed steps detail per lane (S6).
     *
     * @return array<string, int> Lane name => unconsumed count.
     */
    public function unconsumedStepsDetail(): array
    {
        $detail = [];

        // Legacy agent_turn steps
        $agentUnconsumed = max(0, count($this->steps) - $this->cursor);
        if ($agentUnconsumed > 0) {
            $detail[RequestLane::AgentTurn->value] = $agentUnconsumed;
        }

        // Lane-scoped queues
        foreach ($this->laneQueues as $laneKey => $queue) {
            $cursor = $this->laneCursors[$laneKey] ?? 0;
            $unconsumed = max(0, count($queue) - $cursor);
            if ($unconsumed > 0) {
                $detail[$laneKey] = $unconsumed;
            }
        }

        return $detail;
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

    /**
     * Build a lane-specific exhaustion error (S4).
     *
     * @param RequestLane $lane The lane that ran out.
     * @param CapturedPayload $payload The payload that triggered the error.
     * @param int $turn The 1-based turn number.
     */
    private function buildLaneExhaustionError(RequestLane $lane, CapturedPayload $payload, int $turn): RuntimeException
    {
        $msgCount = count($payload->messages);
        $modelHint = $payload->model ? " (model: {$payload->model})" : '';
        $parts = [
            "Response script exhausted on lane '{$lane->value}' — no more steps to serve.",
            "Turn {$turn}, {$msgCount} messages{$modelHint}.",
        ];

        $detail = $this->unconsumedStepsDetail();
        if (empty($detail)) {
            $parts[] = 'All lanes fully consumed.';
        } else {
            $parts[] = 'Unconsumed steps by lane:';
            foreach ($detail as $laneName => $count) {
                $parts[] = "  {$laneName}: {$count}";
            }
        }

        return new RuntimeException(implode("\n", $parts));
    }
}
