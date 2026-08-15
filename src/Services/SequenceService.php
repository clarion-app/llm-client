<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Events\SequenceRunUpdated;
use ClarionApp\LlmClient\Exceptions\SchemaValidationError;
use ClarionApp\LlmClient\Exceptions\SequenceDefinitionValidationException;
use ClarionApp\LlmClient\Jobs\RunSequenceStageJob;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Models\StageSequenceDefinition;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 105-stage-pipeline. The business-logic layer behind SequenceController --
 * defining a sequence, invoking it, and (later) resuming it.
 *
 * Phase 3 (US1) adds defineSequence()/invoke() -- the happy path only
 * (tasks.md "Ordering rationale"): FR-016's pre-check runs before ANY row
 * is created, but the boundary-mismatch (FR-006/FR-007) and stage-failure
 * (FR-008) branches are Phase 5/US3's own addition, layered onto
 * RunSequenceStageJob, not this class. Phase 4 (US2) adds the
 * broadcastRunUpdated()/broadcast() helper (research.md D8, Grounding
 * note item 8) -- a verbatim copy of RunTraceRecorder's/ManagerService's
 * own three-line try/catch shape, so a broadcast failure can never turn
 * an already-successful write's return value into null. Phase 6 (US4)
 * adds resumeSafety().
 */
class SequenceService
{
    public function __construct(
        private readonly SequenceQuery $sequenceQuery,
        private readonly AgentQuery $agentQuery,
        private readonly SchemaValidator $schemaValidator,
        private readonly ContentSanitizer $contentSanitizer,
    ) {}

    /**
     * contracts §1, data-model.md §1/§2/§8. Every stage's helper_agent_id
     * must resolve to an agent the caller owns, is active, AND has an
     * active AgentHelperAssignment with parent_agent_id =
     * $coordinatorAgentId -- the same parent-agent-scoped authorization
     * surface DelegationService::delegate() enforces unconditionally at
     * run time, checked here up front so a broken definition is never
     * created in the first place (data-model.md §8).
     *
     * @param array<int, array<string, mixed>> $stages Each entry:
     *   {name, helper_agent_id, input_schema?, output_schema?, is_idempotent?}
     *
     * @throws SequenceDefinitionValidationException
     */
    public function defineSequence(string $ownerUserId, string $name, ?string $description, string $coordinatorAgentId, array $stages): StageSequenceDefinition
    {
        if (empty($stages)) {
            throw new SequenceDefinitionValidationException('empty_stages', 'A sequence must define at least one stage.');
        }

        $coordinatorAgent = $this->agentQuery->findAgent($ownerUserId, $coordinatorAgentId);
        if ($coordinatorAgent === null || $coordinatorAgent->is_active === false) {
            throw new SequenceDefinitionValidationException(
                'unknown_coordinator_agent',
                'The coordinator agent does not exist, is not owned by you, or is not active.',
            );
        }

        foreach ($stages as $index => $stageInput) {
            $position = $index + 1;
            $helperAgentId = $stageInput['helper_agent_id'] ?? null;

            $helperAgent = $helperAgentId !== null ? $this->agentQuery->findAgent($ownerUserId, $helperAgentId) : null;

            $hasActiveAssignment = $helperAgentId !== null && AgentHelperAssignment::where('parent_agent_id', $coordinatorAgentId)
                ->where('helper_agent_id', $helperAgentId)
                ->whereNull('deleted_at')
                ->exists();

            if ($helperAgent === null || $helperAgent->is_active === false || !$hasActiveAssignment) {
                throw new SequenceDefinitionValidationException(
                    'unknown_helper_agent',
                    "Stage {$position}'s helper agent does not exist, is not owned by you, is not active, or is not an assigned helper of the coordinator agent.",
                    $position,
                );
            }

            foreach (['input_schema', 'output_schema'] as $schemaKey) {
                $schema = $stageInput[$schemaKey] ?? null;
                if ($schema !== null) {
                    $this->assertValidJsonSchema($schema, $position);
                }
            }
        }

        return DB::transaction(function () use ($ownerUserId, $name, $description, $coordinatorAgentId, $stages) {
            $definition = StageSequenceDefinition::create([
                'owner_user_id' => $ownerUserId,
                'coordinator_agent_id' => $coordinatorAgentId,
                'name' => $name,
                'description' => $description,
            ]);

            foreach ($stages as $index => $stageInput) {
                Stage::create([
                    'sequence_definition_id' => $definition->id,
                    'position' => $index + 1,
                    'name' => $stageInput['name'],
                    'helper_agent_id' => $stageInput['helper_agent_id'],
                    'input_schema' => $stageInput['input_schema'] ?? null,
                    'output_schema' => $stageInput['output_schema'] ?? null,
                    'is_idempotent' => (bool) ($stageInput['is_idempotent'] ?? false),
                ]);
            }

            return $definition->fresh(['stages']);
        });
    }

    /**
     * contracts §3, data-model.md §3-§5/§8. FR-016's pre-check re-validates
     * every stage's helper_agent_id AND the definition's own
     * coordinator_agent_id before creating anything -- an
     * AgentHelperAssignment made at definition time can be revoked before
     * invocation, and either agent can be deactivated/removed in the
     * meantime. On success: one dedicated Conversation bound to
     * coordinator_agent_id, one SequenceRun, and one pending StageResult
     * per Stage, all inside a single transaction; RunSequenceStageJob is
     * dispatched only once that transaction has committed.
     *
     * @return array{sequence_run: SequenceRun}|array{error: string, message: string, stage_id?: string, stage_position?: int}
     */
    public function invoke(string $callerUserId, string $definitionId, array $startingInput): array
    {
        $definition = $this->sequenceQuery->findDefinition($callerUserId, $definitionId);
        if ($definition === null) {
            return ['error' => 'definition_not_found', 'message' => 'Sequence definition not found.'];
        }

        $coordinatorAgent = $this->agentQuery->findAgent($callerUserId, $definition->coordinator_agent_id);
        if ($coordinatorAgent === null || $coordinatorAgent->is_active === false) {
            return [
                'error' => 'stage_unavailable',
                'message' => 'The coordinator agent is no longer available.',
            ];
        }

        $stages = Stage::where('sequence_definition_id', $definition->id)->orderBy('position')->get();

        foreach ($stages as $stage) {
            $helperAgent = $this->agentQuery->findAgent($callerUserId, $stage->helper_agent_id);
            $hasActiveAssignment = AgentHelperAssignment::where('parent_agent_id', $definition->coordinator_agent_id)
                ->where('helper_agent_id', $stage->helper_agent_id)
                ->whereNull('deleted_at')
                ->exists();

            if ($helperAgent === null || $helperAgent->is_active === false || !$hasActiveAssignment) {
                return [
                    'error' => 'stage_unavailable',
                    'message' => "Stage \"{$stage->name}\" is no longer available -- its helper agent is inactive, unassigned, or no longer exists.",
                    'stage_id' => $stage->id,
                    'stage_position' => $stage->position,
                ];
            }
        }

        // D1: a brand-new, dedicated Conversation for this one run --
        // ManagerService::createManagedTask()'s own Conversation::create()
        // recipe (data-model.md §3/§8), bound to the definition's
        // coordinator_agent_id.
        $resolution = app(RoleResolver::class)->resolve(ModelRole::Inference, $callerUserId);
        $serverId = $resolution->hasEffectiveModel() ? $resolution->server->id : null;
        $modelName = $resolution->hasEffectiveModel() ? $resolution->model : null;

        $run = DB::transaction(function () use ($callerUserId, $definition, $coordinatorAgent, $startingInput, $stages, $serverId, $modelName) {
            $conversation = Conversation::create([
                'user_id' => $callerUserId,
                'server_id' => $serverId,
                'model' => $modelName,
                'character' => 'Clarion',
                'channel' => 'sequence-run',
                'agent_id' => $coordinatorAgent->id,
                'agent_version_id' => $coordinatorAgent->current_version_id,
            ]);

            $run = SequenceRun::create([
                'sequence_definition_id' => $definition->id,
                'owner_user_id' => $callerUserId,
                'conversation_id' => $conversation->id,
                'status' => 'in_progress',
                'starting_input' => $this->contentSanitizer->truncate(json_encode($startingInput)),
                'current_stage_position' => null,
                'last_progress_at' => now(),
                'resume_count' => 0,
                'started_at' => now(),
            ]);

            foreach ($stages as $stage) {
                StageResult::create([
                    'sequence_run_id' => $run->id,
                    'stage_id' => $stage->id,
                    'status' => 'pending',
                ]);
            }

            return $run;
        });

        RunSequenceStageJob::dispatch($run->id)->onQueue(config('llm-client.pipeline.queue', 'sequence-runs'));

        // research.md D8 (Phase 4/US2): run creation is one of the five
        // named broadcast points.
        $this->broadcastRunUpdated($run->id);

        return ['sequence_run' => $run];
    }

    /**
     * research.md D8/contracts §6 (Phase 4, US2). Fires SequenceRunUpdated
     * for the given run id, wrapped in the private broadcast() try/catch
     * helper below so a broadcast failure can never turn an already-
     * successful write's return value into null. Public so
     * RunSequenceStageJob (a different write point -- each stage
     * transitioning to running, each stage reaching a terminal per-stage
     * status, and run finalization) and SequenceController::resume()
     * (Phase 6) can call it too, rather than duplicating the try/catch
     * shape at every call site.
     */
    public function broadcastRunUpdated(string $sequenceRunId): void
    {
        $this->broadcast(fn () => event(new SequenceRunUpdated($sequenceRunId)));
    }

    /**
     * Verbatim copy of RunTraceRecorder's/ManagerService's own three-line
     * try/catch shape (research.md D8, Grounding note item 8) -- not a
     * shared trait, per those two features' own established precedent of
     * each declaring their own private copy.
     */
    private function broadcast(\Closure $emit): void
    {
        try {
            $emit();
        } catch (\Throwable $e) {
            Log::warning('SequenceService: broadcast failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * contracts §1's `invalid_schema` 422 -- checked with the SAME
     * SchemaValidator machinery used at run time (Grounding note item 3),
     * so a malformed schema is caught at definition time, not discovered
     * mid-run. A permissive `{}` payload is validated against the
     * supplied schema; a SchemaValidationError caused by the schema ITSELF
     * being malformed (thrown from inside the JSON Schema library before
     * SchemaValidator ever reaches its own isValid() check -- e.g. an
     * unrecognized `type` keyword) is a genuine invalid_schema. A
     * SchemaValidationError caused only by `{}` not SATISFYING an
     * otherwise well-formed schema (e.g. a `required` property `{}` lacks)
     * is not an error here -- that is the schema working exactly as
     * declared against the wrong payload, not evidence the schema itself
     * is broken.
     *
     * @param mixed $schema
     */
    private function assertValidJsonSchema($schema, int $stagePosition): void
    {
        try {
            $this->schemaValidator->validate('{}', $schema);
        } catch (SchemaValidationError $e) {
            if (str_starts_with($e->getMessage(), 'Malformed schema:') || str_starts_with($e->getMessage(), 'Schema validation error:')) {
                throw new SequenceDefinitionValidationException('invalid_schema', $e->getMessage(), $stagePosition);
            }
        }
    }
}
