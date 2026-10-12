<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\ValueObjects\PauseKind;
use ClarionApp\LlmClient\ValueObjects\PausedTurnContext;

/**
 * The one place a paused model turn is written into, and consumed out of,
 * the paused assistant message's `tool_data` — for every kind of pause.
 *
 * A model turn may carry several tool calls. When one of them pauses the
 * turn, the calls before it have already run and their results are kept
 * (`pending_tool_results`); the calls after it never run and are answered
 * with a synthesized not-executed result. Resuming only ever adds the
 * paused call's own result, so nothing in the turn is executed twice and
 * every call ends up answered exactly once, in `tool_calls` order.
 *
 * Rows written before this shape existed have neither `pause_kind` nor
 * `paused_tool_call_id`: they are confirmations whose paused call is
 * `tool_calls[0]`, and they are consumed exactly as they always were.
 */
class PausedTurnWriter
{
    /**
     * Record the pause. The caller has already stored the turn's full
     * `tool_calls` batch on $message.
     *
     * @param  array<string, mixed>  $pendingPayload  the kind's pending
     *   payload: the confirmation details, or `{question_id}`
     */
    public function pause(Message $message, PauseKind $kind, array $pendingPayload, PausedTurnContext $context): void
    {
        $toolData = is_array($message->tool_data) ? $message->tool_data : [];
        $toolCalls = array_values($toolData['tool_calls'] ?? []);

        $toolData['tool_results'] = null;
        $toolData['iteration'] = $context->iteration;
        $toolData['pause_kind'] = $kind->value;
        $toolData['paused_tool_call_id'] = $context->pausedToolCallId;
        $toolData['pending_tool_results'] = $this->pendingResults($toolCalls, $kind, $context);
        $toolData['pending_confirmation'] = $kind === PauseKind::Confirmation ? $pendingPayload : null;
        if ($kind === PauseKind::Question) {
            $toolData['pending_question'] = $pendingPayload;
        } else {
            unset($toolData['pending_question']);
        }
        $toolData['run_id'] = $context->runId;
        $toolData['step_id'] = $context->stepId;
        // Millisecond precision: the step's recorded human wait is measured
        // from this stamp, and a whole-second stamp overstates it.
        $toolData['paused_at'] = now()->format('Y-m-d\TH:i:s.vP');
        $toolData['action_id'] = $context->actionId;

        $message->tool_data = $toolData;
        $message->save();
    }

    /**
     * Answer the paused call with $toolResultContent, merge it with the
     * results recorded at the pause into `tool_results` (ordered by
     * `tool_calls`), and clear the pending keys.
     *
     * @param  array<string, mixed>  $extra  additional keys stored beside
     *   the paused call's result (e.g. condensation metadata)
     */
    public function consume(Message $message, string $toolResultContent, array $extra = []): void
    {
        $toolData = is_array($message->tool_data) ? $message->tool_data : [];
        $toolCalls = array_values($toolData['tool_calls'] ?? []);
        $kind = PauseKind::fromToolData($toolData);

        $pausedId = $toolData['paused_tool_call_id'] ?? ($toolCalls[0]['id'] ?? null);

        $results = [];
        foreach ($toolData['pending_tool_results'] ?? [] as $entry) {
            if (($entry['tool_call_id'] ?? null) !== $pausedId) {
                $results[] = $entry;
            }
        }
        $results[] = ['tool_call_id' => $pausedId, 'content' => $toolResultContent] + $extra;

        $toolData['tool_results'] = $this->orderByToolCalls($results, $toolCalls);
        $toolData['pending_tool_results'] = null;
        $toolData[$kind->pendingKey()] = null;

        $message->tool_data = $toolData;
        $message->save();
    }

    /**
     * The id of the call a stored paused turn is waiting on.
     */
    public static function pausedToolCallId(array $toolData): ?string
    {
        return $toolData['paused_tool_call_id'] ?? ($toolData['tool_calls'][0]['id'] ?? null);
    }

    /**
     * Results already produced in the turn, plus a not-executed result for
     * every call after the pausing call that has none.
     *
     * @param  list<array<string, mixed>>  $toolCalls
     * @return list<array<string, mixed>>
     */
    private function pendingResults(array $toolCalls, PauseKind $kind, PausedTurnContext $context): array
    {
        $results = [];
        $answered = [];
        foreach ($context->pendingToolResults as $entry) {
            if (($entry['tool_call_id'] ?? null) === $context->pausedToolCallId) {
                continue;
            }
            $results[] = $entry;
            $answered[$entry['tool_call_id'] ?? ''] = true;
        }

        $afterPause = false;
        foreach ($toolCalls as $call) {
            $id = $call['id'] ?? '';

            if ($id === $context->pausedToolCallId) {
                $afterPause = true;

                continue;
            }

            if ($afterPause && !isset($answered[$id])) {
                $results[] = ['tool_call_id' => $id, 'content' => $kind->notExecutedResult()];
                $answered[$id] = true;
            }
        }

        return $this->orderByToolCalls($results, $toolCalls);
    }

    /**
     * Stable sort into `tool_calls` order; entries naming no known call
     * keep their relative order at the end.
     *
     * @param  list<array<string, mixed>>  $results
     * @param  list<array<string, mixed>>  $toolCalls
     * @return list<array<string, mixed>>
     */
    private function orderByToolCalls(array $results, array $toolCalls): array
    {
        $position = [];
        foreach ($toolCalls as $index => $call) {
            $position[$call['id'] ?? ''] ??= $index;
        }

        $keyed = [];
        foreach ($results as $index => $entry) {
            $keyed[] = [$position[$entry['tool_call_id'] ?? ''] ?? PHP_INT_MAX, $index, $entry];
        }

        usort($keyed, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn ($item) => $item[2], $keyed);
    }
}
