<?php

namespace Tests\Integration\Harness;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Helpers served by what their own history shows rather than by the order
 * of calls (a resume may take its helpers in any order), and a few readers
 * of what a delegation tree left behind.
 *
 * For use in an AssembledSystemTestCase that also uses
 * TaskBudgetHelperJourneys.
 */
trait ServesHelpers
{
    /**
     * Serve the helper whose task reads $task from what its history shows:
     * with no tool result yet it stores note $note (when given) and with
     * one it answers with $summary. Independent of how many calls other
     * work makes in between, so a resumed helper is served the same
     * whichever order a resume takes them in. $agentName tells apart
     * helpers that are given the same task.
     */
    protected function serveHelperTask(string $task, ?string $note, string $summary, ?string $agentName = null): void
    {
        $noteStep = $note === null ? null : (new ResponseScript())->toolRequests([[
            'id' => 'call_note_'.md5($task),
            'name' => 'execute_operation',
            'arguments' => ['operationId' => 'storeNote', 'parameters' => ['body' => ['text' => $note]]],
        ]])->steps[0];
        $answer = (new ResponseScript())->finalAnswer(json_encode(['status' => 'success', 'summary' => $summary, 'output' => (object) [], 'undone' => '']))->steps[0];

        $this->script()->addRule(new LaneRule(
            lane: RequestLane::AgentTurn,
            predicate: function (CapturedPayload $payload) use ($task, $agentName) {
                $isAsked = false;
                $isThatAgent = $agentName === null;
                foreach ($payload->messages as $message) {
                    $content = $message['content'] ?? null;
                    if (!is_string($content)) {
                        continue;
                    }
                    if (($message['role'] ?? null) === 'user' && str_contains($content, "## Task\n{$task}\n")) {
                        $isAsked = true;
                    }
                    // The system prompt lists every agent's instructions ("I am X."),
                    // the speaking agent's own last.
                    if ($agentName !== null && ($message['role'] ?? null) === 'system' && preg_match_all('/I am ([A-Za-z0-9-]+)\./', $content, $names) > 0) {
                        $isThatAgent = end($names[1]) === $agentName;
                    }
                }

                return $isAsked && $isThatAgent;
            },
            respond: function (CapturedPayload $payload) use ($noteStep, $answer) {
                $hasToolResult = collect($payload->messages)->contains(fn ($m) => ($m['role'] ?? null) === 'tool');

                return ($noteStep !== null && !$hasToolResult) ? $noteStep : $answer;
            },
            label: "helper:{$task}:{$agentName}",
        ));
    }

    /**
     * Drop what a kill left unconsumed and keep what was consumed, so the
     * resume is served only what the test then scripts. (The work that ran
     * on past the kill point in-process consumed whatever it needed; a real
     * kill would have consumed nothing more.)
     */
    protected function discardUnconsumedScript(): void
    {
        $script = $this->script();
        $script->steps = array_slice($script->steps, 0, $script->cursor);
    }

    /** @return list<object> the helper conversation's run rows, oldest first */
    protected function runsOfConversation(string $conversationId): array
    {
        // The conversation's work runs: the title a first exchange gets is made in a run of its own, which is not work.
        return DB::table('agent_runs')->where('conversation_id', $conversationId)->where('kind', 'interactive')->orderBy('started_at')->get()->all();
    }

    /** How many tool results answering $toolCallId the conversation's step messages hold. */
    protected function resultCountFor(Conversation $conversation, string $toolCallId): int
    {
        $count = 0;
        foreach (Message::where('conversation_id', $conversation->id)->get() as $message) {
            foreach (($message->tool_data['tool_results'] ?? []) as $result) {
                if (($result['tool_call_id'] ?? null) === $toolCallId) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /** @return list<Delegation> the conversation's delegations, oldest first */
    protected function delegationsOf(Conversation $parent): array
    {
        return Delegation::where('parent_conversation_id', $parent->id)->orderBy('started_at')->get()->all();
    }

    /** The helper note texts the host API was asked to store, in order. */
    protected function storedNotes(): array
    {
        return array_map(fn (array $write) => (string) ($write['text'] ?? ''), $this->noteWrites);
    }
}
