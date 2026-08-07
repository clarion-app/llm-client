<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Services\ModelPriceService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Auth;

/**
 * Operator-only model price configuration (FR-001–FR-004, FR-017,
 * contracts/cost-api.md §1). Every write supersedes rather than edits —
 * ModelPriceService::setPrice() is the only write path.
 */
class ModelPriceController extends Controller
{
    public function __construct(
        private readonly ModelPriceService $service,
    ) {}

    public function index(Request $request)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $historyRequested = $request->boolean('history');

        $query = ModelPrice::query()
            ->orderBy('provider_type')
            ->orderBy('model')
            ->orderBy('effective_from');

        if (!$historyRequested) {
            $query->whereNull('effective_until');
        }

        $data = $query->get()->map(fn (ModelPrice $price) => $this->formatPrice($price))->values();

        return response()->json([
            'currency' => config('llm-client.cost.currency'),
            'data' => $data,
        ], 200);
    }

    public function store(Request $request)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'provider_type' => ['required', 'string'],
            'model' => ['required', 'string'],
            'reused_input_rate' => ['required', 'numeric', 'min:0'],
            'fresh_input_rate' => ['required', 'numeric', 'min:0'],
            'output_rate' => ['required', 'numeric', 'min:0'],
            'effective_from' => ['sometimes', 'date'],
        ]);

        $effectiveFrom = isset($validated['effective_from'])
            ? Carbon::parse($validated['effective_from'])
            : null;

        $result = $this->service->setPrice(
            $validated['provider_type'],
            $validated['model'],
            [
                'reused_input_rate' => (string) $validated['reused_input_rate'],
                'fresh_input_rate' => (string) $validated['fresh_input_rate'],
                'output_rate' => (string) $validated['output_rate'],
            ],
            $effectiveFrom,
        );

        $response = $this->formatPrice($result['price']);
        $response['previous_effective_until'] = $result['previous_effective_until']
            ? Carbon::parse($result['previous_effective_until'])->toJSON()
            : null;
        $response['currency'] = config('llm-client.cost.currency');

        return response()->json($response, 200);
    }

    private function formatPrice(ModelPrice $price): array
    {
        return [
            'provider_type' => $price->provider_type,
            'model' => $price->model,
            'reused_input_rate' => $price->reused_input_rate,
            'fresh_input_rate' => $price->fresh_input_rate,
            'output_rate' => $price->output_rate,
            'effective_from' => optional($price->effective_from)->toJSON(),
            'effective_until' => optional($price->effective_until)->toJSON(),
        ];
    }
}
