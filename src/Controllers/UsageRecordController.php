<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Support\Decimal;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Auth;

/**
 * Per-record cost detail (FR-019, contracts/cost-api.md §2).
 *
 * show() follows ConversationController::show()'s idiom —
 * UsageRecord::findOrFail() (404 when absent) then an explicit 403 when
 * found but foreign-owned, with an operator bypassing the ownership check
 * entirely (FR-017-style role scoping).
 */
class UsageRecordController extends Controller
{
    public function show(Request $request, string $id): JsonResponse
    {
        $record = UsageRecord::findOrFail($id);

        $isOperator = OperatorAccess::isOperator(Auth::id());

        if (!$isOperator && $record->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'currency' => config('llm-client.cost.currency'),
            'id' => $record->id,
            'conversation_id' => $record->conversation_id,
            'user_id' => $record->user_id,
            'agent_id' => $record->agent_id,
            'model' => $record->model,
            'provider_type' => $record->provider_type,
            'input_tokens' => $record->input_tokens,
            'reused_input_tokens' => $record->reused_input_tokens,
            'fresh_input_tokens' => $record->fresh_input_tokens,
            'output_tokens' => $record->output_tokens,
            'cost' => [
                'unpriced' => $record->cost_unpriced,
                'estimated' => $record->cost_estimated,
                'reused_input_cost' => $this->formatCost($record->reused_input_cost),
                'fresh_input_cost' => $this->formatCost($record->fresh_input_cost),
                'output_cost' => $this->formatCost($record->output_cost),
                'total_cost' => $this->formatCost($record->total_cost),
            ],
            'created_at' => $record->created_at,
        ]);
    }

    /**
     * The decimal(20,10) cost columns are read back untyped (UsageRecord's
     * $casts deliberately excludes them, research.md D1) so that SQLite's
     * NUMERIC storage affinity — which stores a whole-number value like
     * exactly 0 as an integer rather than as text — never gets a chance to
     * silently promote a stored cost into a PHP float. Re-rounding through
     * Decimal::round() here restores the full decimal-string shape
     * (e.g. "0.0000000000") that contracts/cost-api.md §2 documents, without
     * ever forming a float in between. null (unpriced) passes through
     * unchanged (FR-006 — never a fabricated zero).
     */
    private function formatCost(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Decimal::round((string) $value, 10);
    }
}
