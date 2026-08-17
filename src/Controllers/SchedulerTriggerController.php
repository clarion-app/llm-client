<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\SchedulerTrigger;
use ClarionApp\LlmClient\Services\AgentVersionResolver;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD surface for a caller's own scheduler triggers. Human-driven
 * configuration only -- never named in scheduler.yaml's own tools.allow, the
 * same posture CodingProjectController already holds for coding-project
 * registration: a trigger is set up by a person, not created by an agent
 * mid-run.
 *
 * Every ownership check is a direct `where('user_id', Auth::id())` filter,
 * the same shape CodingProjectController already uses -- no new
 * identifier-comparison code, and every absent-or-foreign-owned id answers
 * the same uniform 404 RunController::notFoundResponse() already
 * established, never a distinguishing 403.
 */
class SchedulerTriggerController extends Controller
{
    private const AGENT_TEMPLATE_NAME = 'scheduler';

    private const CONDITION_COMPARATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains'];

    public function __construct(
        private readonly AgentVersionResolver $agentVersionResolver,
    ) {
    }

    /**
     * POST scheduler-triggers. 422 when agent_id does not reference a
     * scheduler-named agent owned by the caller, when kind-conditional
     * fields are malformed/missing, or when condition_operation_id is not
     * permitted by the bound agent definition.
     *
     * retry_limit defaulting is applied exactly once, here: an omitted
     * retry_limit persists config('llm-client.scheduler.default_retry_limit'),
     * never null; an explicitly-supplied 0 persists 0 exactly, never coerced
     * to the default. This is the only place either rule is applied -- no
     * later reader re-defaults it.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_id' => 'required|uuid',
            'name' => 'required|string|max:255',
            'kind' => ['required', Rule::in([SchedulerTrigger::KIND_SCHEDULE, SchedulerTrigger::KIND_CONDITION])],
            'schedule_expression' => 'nullable|string',
            'condition_operation_id' => 'nullable|string',
            'condition_path' => 'nullable|string',
            'condition_comparator' => ['nullable', Rule::in(self::CONDITION_COMPARATORS)],
            'condition_value' => 'nullable|string',
            'defined_work' => 'required|string',
            'retry_limit' => 'nullable|integer|min:0',
        ]);

        $agent = Agent::where('id', $validated['agent_id'])
            ->where('user_id', Auth::id())
            ->where('name', self::AGENT_TEMPLATE_NAME)
            ->first();

        if ($agent === null) {
            return response()->json([
                'errors' => [
                    'agent_id' => ['Must reference a scheduler agent owned by the caller.'],
                ],
            ], 422);
        }

        $kindErrors = $this->validateKindFields($validated['kind'], $validated);
        if ($kindErrors !== []) {
            return response()->json(['errors' => $kindErrors], 422);
        }

        if ($validated['kind'] === SchedulerTrigger::KIND_CONDITION) {
            $definition = $this->resolveDefinition($agent);

            if ($definition === null || !$definition->isOperationPermitted($validated['condition_operation_id'])) {
                return response()->json([
                    'errors' => [
                        'condition_operation_id' => ['Not permitted by the bound agent definition.'],
                    ],
                ], 422);
            }
        }

        $retryLimit = array_key_exists('retry_limit', $validated) && $validated['retry_limit'] !== null
            ? (int) $validated['retry_limit']
            : (int) config('llm-client.scheduler.default_retry_limit', 3);

        $trigger = SchedulerTrigger::create([
            'user_id' => Auth::id(),
            'agent_id' => $agent->id,
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'schedule_expression' => $validated['schedule_expression'] ?? null,
            'condition_operation_id' => $validated['condition_operation_id'] ?? null,
            'condition_path' => $validated['condition_path'] ?? null,
            'condition_comparator' => $validated['condition_comparator'] ?? null,
            'condition_value' => $validated['condition_value'] ?? null,
            'defined_work' => $validated['defined_work'],
            'retry_limit' => $retryLimit,
            'is_active' => true,
        ]);

        return response()->json($trigger, 201);
    }

    /**
     * GET scheduler-triggers. Lists only the caller's own, non-trashed
     * triggers.
     */
    public function index(Request $request): JsonResponse
    {
        $triggers = SchedulerTrigger::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($triggers, 200);
    }

    /**
     * GET scheduler-triggers/{id}. Uniform 404 for both an absent id and one
     * owned by a different user -- never a distinguishing 403.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $trigger = $this->ownedTrigger($id);

        if ($trigger === null) {
            return $this->notFoundResponse();
        }

        return response()->json($trigger, 200);
    }

    /**
     * PUT scheduler-triggers/{id}. Partial update. kind itself is immutable
     * -- a request attempting to change it is a 422, never silently ignored
     * or silently applied.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $trigger = $this->ownedTrigger($id);

        if ($trigger === null) {
            return $this->notFoundResponse();
        }

        $validated = $request->validate([
            'kind' => ['sometimes', Rule::in([SchedulerTrigger::KIND_SCHEDULE, SchedulerTrigger::KIND_CONDITION])],
            'name' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
            'defined_work' => 'sometimes|string',
            'retry_limit' => 'sometimes|integer|min:0',
            'schedule_expression' => 'sometimes|nullable|string',
            'condition_operation_id' => 'sometimes|nullable|string',
            'condition_path' => 'sometimes|nullable|string',
            'condition_comparator' => ['sometimes', 'nullable', Rule::in(self::CONDITION_COMPARATORS)],
            'condition_value' => 'sometimes|nullable|string',
        ]);

        if (array_key_exists('kind', $validated) && $validated['kind'] !== $trigger->kind) {
            return response()->json([
                'errors' => [
                    'kind' => ['kind is immutable after creation; delete and recreate the trigger instead.'],
                ],
            ], 422);
        }
        unset($validated['kind']);

        if ($trigger->kind === SchedulerTrigger::KIND_SCHEDULE
            && array_key_exists('schedule_expression', $validated)
            && $validated['schedule_expression'] !== null
            && !CronExpression::isValidExpression($validated['schedule_expression'])
        ) {
            return response()->json([
                'errors' => [
                    'schedule_expression' => ['Must be a valid cron expression.'],
                ],
            ], 422);
        }

        $trigger->update($validated);

        return response()->json($trigger->fresh(), 200);
    }

    /**
     * DELETE scheduler-triggers/{id}. Soft delete, ownership-checked. The
     * trigger's historical scheduler_trigger_firings/agent_runs rows remain
     * intact and queryable -- only the evaluator's own active/non-trashed
     * query stops considering this trigger going forward.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $trigger = $this->ownedTrigger($id);

        if ($trigger === null) {
            return $this->notFoundResponse();
        }

        $trigger->delete();

        return response()->json([], 204);
    }

    private function ownedTrigger(string $id): ?SchedulerTrigger
    {
        return SchedulerTrigger::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, list<string>>
     */
    private function validateKindFields(string $kind, array $validated): array
    {
        $errors = [];
        $conditionFields = ['condition_operation_id', 'condition_path', 'condition_comparator', 'condition_value'];

        if ($kind === SchedulerTrigger::KIND_SCHEDULE) {
            $expression = $validated['schedule_expression'] ?? null;

            if ($expression === null || $expression === '') {
                $errors['schedule_expression'] = ['Required when kind is schedule.'];
            } elseif (!CronExpression::isValidExpression($expression)) {
                $errors['schedule_expression'] = ['Must be a valid cron expression.'];
            }

            foreach ($conditionFields as $field) {
                if (($validated[$field] ?? null) !== null) {
                    $errors[$field] = ['Must be omitted when kind is schedule.'];
                }
            }

            return $errors;
        }

        foreach ($conditionFields as $field) {
            if (($validated[$field] ?? null) === null || $validated[$field] === '') {
                $errors[$field] = ['Required when kind is condition.'];
            }
        }

        if (($validated['schedule_expression'] ?? null) !== null) {
            $errors['schedule_expression'] = ['Must be omitted when kind is condition.'];
        }

        return $errors;
    }

    private function resolveDefinition(Agent $agent): ?\ClarionApp\LlmClient\ValueObjects\AgentDefinition
    {
        try {
            return $this->agentVersionResolver->currentDefinitionFor($agent);
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException) {
            return null;
        }
    }

    /**
     * The single uniform "not found" body for an absent, purged, or
     * not-owned-by-the-caller trigger id -- matches
     * RunController::notFoundResponse()'s own established precedent
     * (never a distinct 403 that would reveal existence).
     */
    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'Scheduler trigger not found',
            'code' => 'scheduler_trigger_not_found',
        ], 404);
    }
}
