<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Services\RateLimitService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use ClarionApp\LlmClient\ValueObjects\RateLimitScope;
use Illuminate\Http\Request;
use Auth;

/**
 * Operator-only rate limit configuration: the general limit that applies to
 * every user with no override of their own.
 *
 * Two properties of this controller are deliberate rather than incidental:
 *
 *  - Every action checks operator access first and answers 403 otherwise,
 *    including for a non-operator acting on their own limit. Reading the
 *    list is as restricted as writing it.
 *  - None of these routes is ever subject to rate-limit enforcement. An
 *    operator who has been rate-limited must always retain the ability to
 *    raise or waive their own limit.
 *
 * RateLimitService is the sole write path; this controller only translates
 * HTTP to it, and translates its \InvalidArgumentException rejections to a
 * 422.
 *
 * putUser()/destroyUser() raise, lower, or waive one specific user's limit
 * without touching the default that applies to everyone else — the same
 * operator gate, the same validation, the same restore-not-duplicate
 * write path, just a different scope id.
 */
class RateLimitController extends Controller
{
    public function __construct(
        private readonly RateLimitService $service,
    ) {}

    public function index()
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $data = $this->service->list()
            ->map(fn (RateLimit $limit) => $this->formatLimit($limit))
            ->values();

        return response()->json(['data' => $data], 200);
    }

    public function putUserDefault(Request $request)
    {
        return $this->put($request, RateLimitScope::UserDefault, RateLimit::INSTALLATION_SCOPE_ID);
    }

    public function destroyUserDefault()
    {
        return $this->destroy(RateLimitScope::UserDefault, RateLimit::INSTALLATION_SCOPE_ID);
    }

    public function putUser(Request $request, string $userId)
    {
        return $this->put($request, RateLimitScope::User, $userId);
    }

    public function destroyUser(string $userId)
    {
        return $this->destroy(RateLimitScope::User, $userId);
    }

    private function put(Request $request, RateLimitScope $scopeType, string $scopeId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        // only() omits a key the caller did not send, which is what keeps
        // an unknown field from reaching the service at all.
        $attributes = $request->only(['max_requests', 'window_seconds', 'waived']);

        try {
            $limit = $this->service->upsert($scopeType, $scopeId, $attributes);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        return response()->json($this->formatLimit($limit), 200);
    }

    private function destroy(RateLimitScope $scopeType, string $scopeId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $this->service->remove($scopeType, $scopeId);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLimit(RateLimit $limit): array
    {
        return [
            'id' => $limit->id,
            'scope_type' => $limit->scope_type,
            'scope_id' => $limit->scope_id,
            'max_requests' => $limit->max_requests,
            'window_seconds' => $limit->window_seconds,
            'waived' => (bool) $limit->waived,
        ];
    }

    private function forbidden()
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    private function unprocessable(string $message)
    {
        return response()->json([
            'message' => $message,
            'errors' => ['limit' => [$message]],
        ], 422);
    }
}
