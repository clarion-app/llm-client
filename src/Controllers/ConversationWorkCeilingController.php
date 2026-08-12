<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\ConversationWorkCeiling;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Services\ConversationWorkCeilingService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkScope;
use Illuminate\Http\Request;
use Auth;

/**
 * Operator-only conversation work ceiling configuration: the general
 * ceiling that applies to any conversation with no override, and one
 * conversation's own override.
 *
 * Two properties of this controller are deliberate rather than incidental:
 *
 *  - Every action checks operator access first and answers 403 otherwise,
 *    including for a non-operator acting on their own conversation. Reading
 *    the list is as restricted as writing it.
 *  - None of these routes is ever subject to conversation-work
 *    enforcement. Configuration is not agent work.
 *
 * ConversationWorkCeilingService is the sole write path; this controller
 * only translates HTTP to it, and translates its InvalidArgumentException
 * rejections to a 422.
 *
 * putConversation()/destroyConversation() raise, lower, or waive one
 * conversation's ceiling without touching the default that applies to
 * every other conversation — the same operator gate, the same validation,
 * the same restore-not-duplicate write path, just a different scope id.
 */
class ConversationWorkCeilingController extends Controller
{
    public function __construct(
        private readonly ConversationWorkCeilingService $service,
    ) {}

    public function index()
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $data = $this->service->list()
            ->map(fn (ConversationWorkCeiling $ceiling) => $this->formatCeiling($ceiling))
            ->values();

        return response()->json(['data' => $data], 200);
    }

    public function putConversationDefault(Request $request)
    {
        return $this->put($request, ConversationWorkScope::ConversationDefault, RateLimit::INSTALLATION_SCOPE_ID);
    }

    public function destroyConversationDefault()
    {
        return $this->destroy(ConversationWorkScope::ConversationDefault, RateLimit::INSTALLATION_SCOPE_ID);
    }

    public function putConversation(Request $request, string $conversationId)
    {
        return $this->put($request, ConversationWorkScope::Conversation, $conversationId);
    }

    public function destroyConversation(string $conversationId)
    {
        return $this->destroy(ConversationWorkScope::Conversation, $conversationId);
    }

    private function put(Request $request, ConversationWorkScope $scopeType, string $scopeId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        // only() omits a key the caller did not send, which is what keeps
        // an unknown field from reaching the service at all.
        $attributes = $request->only(['max_work_units', 'window_seconds', 'waived']);

        try {
            $ceiling = $this->service->upsert($scopeType, $scopeId, $attributes);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        return response()->json($this->formatCeiling($ceiling), 200);
    }

    private function destroy(ConversationWorkScope $scopeType, string $scopeId)
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
    private function formatCeiling(ConversationWorkCeiling $ceiling): array
    {
        return [
            'id' => $ceiling->id,
            'scope_type' => $ceiling->scope_type,
            'scope_id' => $ceiling->scope_id,
            'max_work_units' => $ceiling->max_work_units,
            'window_seconds' => $ceiling->window_seconds,
            'waived' => (bool) $ceiling->waived,
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
            'errors' => ['ceiling' => [$message]],
        ], 422);
    }
}
