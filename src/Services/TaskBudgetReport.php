<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetStanding;

/**
 * Composes the report a user reads when a task meets its budget, from what
 * the task already recorded: the ceiling reached, what was used, the tool
 * calls completed and the agent's latest progress in the task's root
 * conversation since the task opened, and what can happen next. Composing it
 * makes no model request and records no usage. Every sentence comes from
 * TaskBudgetText.
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

        $sections[] = $this->text->reportNothingOutstanding();
        $sections[] = $next;

        return implode("\n\n", $sections);
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
