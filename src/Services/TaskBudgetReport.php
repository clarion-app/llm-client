<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetStanding;

/**
 * Composes the report a user reads when a task meets its budget, from what
 * the task already recorded: the ceiling reached, what was used, the tool
 * calls completed and the agent's latest progress in the task's root
 * conversation since the task opened, each helper's outcome, what is not
 * finished, and what can happen next. Composing it makes no model request
 * and records no usage. Every sentence comes from TaskBudgetText.
 */
class TaskBudgetReport
{
    public const OCCASION_PAUSED = 'paused';
    public const OCCASION_ACCEPTED = 'accepted';
    public const OCCASION_STOPPED = 'stopped';
    public const OCCASION_EXPIRED = 'expired';
    public const OCCASION_HALTED = 'halted';

    private const PROGRESS_CHARS = 500;

    public function __construct(private TaskBudgetText $text)
    {
    }

    public function compose(TaskBudget $task, TaskBudgetStanding $standing, string $occasion): string
    {
        $next = $this->text->reportNext($occasion);

        $messages = $this->messagesSinceOpened($task);

        $sections = [
            $this->text->reportHeadline($standing),
            $this->text->reportConsumption($standing),
            $this->text->reportDone($this->completedToolCalls($messages)),
        ];

        $progress = $this->latestProgress($messages);
        if ($progress !== null) {
            $sections[] = $this->text->reportLatestProgress($progress);
        }

        $helpers = $this->helpers($task);
        if ($helpers !== []) {
            $sections[] = $this->text->reportHelpers($helpers);
        }

        $partsNotAccepted = $this->partsNotAccepted($task);
        if ($partsNotAccepted !== []) {
            $sections[] = $this->text->reportPartsNotAccepted($partsNotAccepted);
        }

        $helperStillWorking = in_array('in_progress', array_column($helpers, 'outcome'), true);
        if (!$helperStillWorking && $partsNotAccepted === []) {
            $sections[] = $this->text->reportNothingOutstanding();
        }
        $sections[] = $next;

        return implode("\n\n", $sections);
    }

    /**
     * The task's helpers — delegations whose helper conversation points at
     * the task — oldest first.
     *
     * @return list<array{name: string, outcome: string, summary: string}>
     */
    private function helpers(TaskBudget $task): array
    {
        $rows = Delegation::query()
            ->join('conversations', 'conversations.id', '=', 'agent_delegations.helper_conversation_id')
            ->where('conversations.active_task_budget_id', $task->id)
            ->orderBy('agent_delegations.started_at')
            ->orderBy('agent_delegations.id')
            ->get(['agent_delegations.*']);

        $helpers = [];
        foreach ($rows as $delegation) {
            $outcome = match (true) {
                in_array($delegation->status, ['queued', 'in_progress'], true) => 'in_progress',
                $delegation->result_reason === 'task_budget' => 'stopped',
                $delegation->status === 'failed' || $delegation->result_status === 'failure' => 'failed',
                $delegation->result_status === 'partial' => 'partial',
                default => 'completed',
            };
            $name = Agent::withTrashed()->find($delegation->helper_agent_id)?->name ?? 'a helper';
            $helpers[] = ['name' => $name, 'outcome' => $outcome, 'summary' => (string) ($delegation->result_summary ?? '')];
        }

        return $helpers;
    }

    /**
     * A managed task's parts not accepted, in part order.
     *
     * @return list<string>
     */
    private function partsNotAccepted(TaskBudget $task): array
    {
        if ($task->managed_task_id === null) {
            return [];
        }

        return ManagedTaskPart::where('managed_task_id', $task->managed_task_id)
            ->where('state', '!=', 'accepted')
            ->orderBy('sequence')
            ->pluck('description')
            ->map(fn ($description) => (string) $description)
            ->all();
    }

    /** @return iterable<Message> */
    private function messagesSinceOpened(TaskBudget $task)
    {
        $since = $task->created_at?->copy()->startOfSecond();

        $query = Message::where('conversation_id', $task->root_conversation_id)->where('role', 'assistant');
        if ($since !== null) {
            $query->where('created_at', '>=', $since->format('Y-m-d H:i:s'));
        }

        return $query->orderBy('created_at')->get();
    }

    /** @return array<string, int> */
    private function completedToolCalls($messages): array
    {
        $counts = [];
        foreach ($messages as $message) {
            $data = is_array($message->tool_data) ? $message->tool_data : [];
            if (in_array($data['kind'] ?? null, AgentQuestionService::CONVERSATION_ONLY_KINDS, true)) {
                continue;
            }
            $completed = [];
            foreach ((array) ($data['tool_results'] ?? []) as $result) {
                if (isset($result['tool_call_id'])) {
                    $completed[(string) $result['tool_call_id']] = true;
                }
            }
            foreach ((array) ($data['tool_calls'] ?? []) as $call) {
                $id = (string) ($call['id'] ?? '');
                $name = (string) ($call['function']['name'] ?? '');
                if ($name === '' || !isset($completed[$id])) {
                    continue;
                }
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function latestProgress($messages): ?string
    {
        foreach (array_reverse($messages->all()) as $message) {
            $data = is_array($message->tool_data) ? $message->tool_data : [];
            if (in_array($data['kind'] ?? null, AgentQuestionService::CONVERSATION_ONLY_KINDS, true)) {
                continue;
            }
            $content = trim((string) $message->content);
            if ($content !== '') {
                return mb_substr($content, 0, self::PROGRESS_CHARS);
            }
        }

        return null;
    }
}
