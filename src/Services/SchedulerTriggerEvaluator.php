<?php

namespace ClarionApp\LlmClient\Services;

use Carbon\Carbon;
use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\McpSession;
use ClarionApp\LlmClient\Models\SchedulerTrigger;
use Cron\CronExpression;
use Illuminate\Support\Facades\Log;

/**
 * Answers "is this trigger due right now, and if so, what is the logical
 * event's own dedup key?" — the one question `llm-client:evaluate-scheduler-
 * triggers` asks of every active trigger it looks at. Never writes a firing
 * itself (the caller wins the dedup latch via an `insertOrIgnore()` keyed on
 * the returned fire key, mirroring `BudgetThresholdNotification`'s own
 * once-per-event latch) — this class only decides "due or not" and, for the
 * condition kind, persists the observation it made along the way.
 *
 * A schedule trigger is due exactly when its cron expression matches the
 * current instant; its fire key carries the current UTC minute, so two
 * evaluations inside the same minute compute the identical key and collide
 * on the same latch row.
 *
 * A condition trigger is due only on a false -> true transition, never on
 * an already-true observation holding steady, and never on its own very
 * first observation (last_condition_state starts null, and null is not
 * false) -- a condition already true when a trigger is first created does
 * not itself count as a becoming-true event. Every evaluation, whether or
 * not it is due, records what it observed on the trigger itself, so the
 * next call has something to compare against.
 */
class SchedulerTriggerEvaluator
{
    public function __construct(
        private readonly McpToolExecutor $toolExecutor,
        private readonly AgentVersionResolver $agentVersionResolver,
    ) {
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    public function evaluate(SchedulerTrigger $trigger): array
    {
        if ($trigger->kind === SchedulerTrigger::KIND_SCHEDULE) {
            return $this->evaluateSchedule($trigger);
        }

        return $this->evaluateCondition($trigger);
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function evaluateSchedule(SchedulerTrigger $trigger): array
    {
        $now = Carbon::now();
        $cron = new CronExpression((string) $trigger->schedule_expression);

        if (!$cron->isDue($now)) {
            return [false, null];
        }

        $fireKey = sprintf('schedule:%s:%s', $trigger->id, $now->format('Y-m-d\TH:i'));

        return [true, $fireKey];
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function evaluateCondition(SchedulerTrigger $trigger): array
    {
        $definition = $this->resolveBoundDefinition($trigger);

        if ($definition === null || !$definition->isOperationPermitted((string) $trigger->condition_operation_id)) {
            Log::warning('Scheduler trigger condition operation is not permitted for its bound agent; skipping.', [
                'trigger_id' => $trigger->id,
                'operation_id' => $trigger->condition_operation_id,
            ]);

            return [false, null];
        }

        $details = (array) ApiManager::getOperationDetails((string) $trigger->condition_operation_id);
        $method = strtoupper((string) ($details['method'] ?? 'GET'));
        $path = (string) ($details['path'] ?? '');

        $session = $this->getOrCreateSession((string) $trigger->user_id);
        $result = $this->toolExecutor->executeHttpCall($method, $path, [], [], $session);

        if ($result['isError'] ?? false) {
            Log::warning('Scheduler trigger condition check failed; skipping.', [
                'trigger_id' => $trigger->id,
                'operation_id' => $trigger->condition_operation_id,
            ]);

            return [false, null];
        }

        $body = $result['content'][0]['text'] ?? '{}';
        $decoded = json_decode($body, true);
        $value = $this->readDotPath(is_array($decoded) ? $decoded : [], (string) $trigger->condition_path);

        $observed = $this->compare($value, (string) $trigger->condition_comparator, (string) $trigger->condition_value);

        if ($observed === true && $trigger->last_condition_state === false) {
            $due = true;
            // Microsecond precision, not the whole-second precision a plain
            // toIso8601String() would give: two genuinely distinct
            // becoming-true events evaluated in rapid succession must never
            // collapse onto the same fire key, unlike the schedule kind's
            // deliberately whole-minute-granular key above.
            $fireKey = sprintf('condition:%s:%s', $trigger->id, Carbon::now('UTC')->format('Y-m-d\TH:i:s.u\Z'));
        } else {
            $due = false;
            $fireKey = null;
        }

        $trigger->update([
            'last_condition_state' => $observed,
            'last_evaluated_at' => Carbon::now(),
        ]);

        return [$due, $fireKey];
    }

    private function resolveBoundDefinition(SchedulerTrigger $trigger): ?\ClarionApp\LlmClient\ValueObjects\AgentDefinition
    {
        $agent = Agent::find($trigger->agent_id);

        if ($agent === null) {
            return null;
        }

        try {
            return $this->agentVersionResolver->currentDefinitionFor($agent);
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException) {
            return null;
        }
    }

    private function getOrCreateSession(string $userId): McpSession
    {
        $session = McpSession::where('user_id', $userId)->first();

        if ($session === null) {
            $session = McpSession::create([
                'user_id' => $userId,
                'protocol_version' => '2025-03-26',
            ]);
        }

        return $session;
    }

    private function readDotPath(array $data, string $path): mixed
    {
        $current = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function compare(mixed $value, string $comparator, string $rawTarget): bool
    {
        $target = $this->castComparisonValue($rawTarget);

        return match ($comparator) {
            'eq' => $value == $target,
            'ne' => $value != $target,
            'gt' => $value > $target,
            'gte' => $value >= $target,
            'lt' => $value < $target,
            'lte' => $value <= $target,
            'contains' => is_string($value) && str_contains($value, (string) $target),
            default => false,
        };
    }

    private function castComparisonValue(string $raw): int|float|bool|string
    {
        if ($raw === 'true') {
            return true;
        }

        if ($raw === 'false') {
            return false;
        }

        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        return $raw;
    }
}
