<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Exceptions\InvalidAgentVersionComparisonException;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentVersionComparer;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionParseErrorKind;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionResolutionErrorKind;
use ClarionApp\LlmClient\ValueObjects\AgentVersionFieldDifference;
use ClarionApp\LlmClient\ValueObjects\AgentVersionListDifference;
use ClarionApp\LlmClient\ValueObjects\InvalidAgentVersionComparisonKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * GET /agents/versions/compare (090-agent-version-binding, Phase 5/US3,
 * contracts §4). A new, self-contained controller — 087's
 * StoredAgentController is not modified. Named independently by two
 * version ids (not nested under a single agent, research.md D7), so a
 * cross-agent attempt can be refused with a specific explanation rather
 * than a generic 404.
 *
 * Does its own ownership-scoped lookup for both ids FIRST, before ever
 * calling AgentVersionComparer — mirroring the identical query
 * AgentVersionComparer::findOwnedVersion() runs internally, so the
 * comparer's own defensive \RuntimeException path is unreachable via this
 * controller.
 */
class AgentVersionComparisonController extends Controller
{
    public function compare(Request $request, AgentVersionComparer $comparer): JsonResponse
    {
        $validated = $request->validate([
            'left' => 'required|string',
            'right' => 'required|string',
        ]);

        $left = AgentVersion::where('id', $validated['left'])
            ->whereHas('agent', fn ($q) => $q->where('user_id', Auth::id()))
            ->first();

        $right = AgentVersion::where('id', $validated['right'])
            ->whereHas('agent', fn ($q) => $q->where('user_id', Auth::id()))
            ->first();

        if ($left === null || $right === null) {
            return $this->notFoundResponse();
        }

        try {
            $comparison = $comparer->compare(Auth::id(), $left->id, $right->id);
        } catch (InvalidAgentVersionComparisonException $e) {
            return response()->json([
                'error' => $e->kind === InvalidAgentVersionComparisonKind::SameVersion ? 'same_version' : 'different_agents',
                'message' => $e->getMessage(),
                'kind' => $e->kind->name,
            ], 422);
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException $e) {
            return $this->definitionErrorResponse($e);
        }

        return response()->json([
            'left_version_id' => $comparison->leftVersionId,
            'right_version_id' => $comparison->rightVersionId,
            'identical' => $comparison->identical,
            'field_differences' => array_map(
                fn (AgentVersionFieldDifference $d): array => [
                    'field' => $this->wireFieldName($d->field),
                    'from' => $d->from,
                    'to' => $d->to,
                ],
                $comparison->fieldDifferences,
            ),
            'list_differences' => array_map(
                fn (AgentVersionListDifference $d): array => [
                    'field' => $this->wireFieldName($d->field),
                    'added' => $d->added,
                    'removed' => $d->removed,
                ],
                $comparison->listDifferences,
            ),
        ], 200);
    }

    /**
     * Str::snake() applied only to fields with no "." already in them
     * (contracts §4's own worked example): `toolsAllow` -> `tools_allow`,
     * `capabilities` stays `capabilities`, `memory.long_term` stays
     * `memory.long_term`.
     */
    private function wireFieldName(string $field): string
    {
        return str_contains($field, '.') ? $field : Str::snake($field);
    }

    /**
     * The uniform "not found" body for an absent id, or one that resolves
     * to nothing this caller owns — mirrors
     * StoredAgentController::notFoundResponse()'s exact shape/style.
     */
    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'Agent version not found',
            'code' => 'agent_version_not_found',
        ], 404);
    }

    /**
     * The uniform 422 body for a version whose raw_definition fails to
     * parse or resolve — identical shape to
     * StoredAgentController::definitionErrorResponse().
     */
    private function definitionErrorResponse(AgentDefinitionParseException|AgentDefinitionResolutionException $e): JsonResponse
    {
        return response()->json([
            'error' => $this->errorSlugFor($e),
            'message' => $e->getMessage(),
            'kind' => $e->kind->name,
        ], 422);
    }

    private function errorSlugFor(AgentDefinitionParseException|AgentDefinitionResolutionException $e): string
    {
        if ($e instanceof AgentDefinitionParseException) {
            return match ($e->kind) {
                AgentDefinitionParseErrorKind::MalformedYaml => 'malformed_yaml',
                AgentDefinitionParseErrorKind::UnrecognizedFormatVersion => 'unrecognized_format_version',
                AgentDefinitionParseErrorKind::MissingName => 'missing_name',
                AgentDefinitionParseErrorKind::UnknownKey => 'unrecognized_setting',
                AgentDefinitionParseErrorKind::InstructionsTooLong => 'instructions_too_long',
            };
        }

        return match ($e->kind) {
            AgentDefinitionResolutionErrorKind::UnknownModel => 'unknown_model',
            AgentDefinitionResolutionErrorKind::UnknownCapability => 'unknown_capability',
            AgentDefinitionResolutionErrorKind::EmptyOperationPattern => 'empty_operation_pattern',
        };
    }
}
