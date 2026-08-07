<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
                // The decimal(20,10) cost columns are read straight from the
                // model: UsageRecord's PlainDecimalCast already guarantees
                // each one comes back as the exact plain-decimal-notation
                // string it was written as (or null, for unpriced — FR-006,
                // never a fabricated zero), rounded to its own scale via
                // Decimal::round() inside the cast itself. Re-rounding here
                // would be redundant — see that cast's docblock.
                'reused_input_cost' => $record->reused_input_cost,
                'fresh_input_cost' => $record->fresh_input_cost,
                'output_cost' => $record->output_cost,
                'total_cost' => $record->total_cost,
            ],
            // UsageRecord has $timestamps = false (data-model.md §2's
            // "explicit capture" reasoning), so created_at is never an
            // Eloquent-managed date attribute and never passes through the
            // framework's automatic Carbon::toJSON() serialization the way
            // ModelPriceController's effective_from/effective_until do —
            // read back from the DB it is a plain "Y-m-d H:i:s" string.
            // Parsed and re-rendered here so this endpoint matches
            // contracts/cost-api.md §2's documented ISO-8601 shape
            // ("2026-08-07T14:03:11Z") instead of leaking the raw storage
            // format.
            'created_at' => Carbon::parse($record->created_at)->toJSON(),
        ]);
    }
}
