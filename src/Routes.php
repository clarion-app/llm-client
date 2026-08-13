<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use ClarionApp\LlmClient\Controllers\ConversationController;
use ClarionApp\LlmClient\Controllers\ServerController;
use ClarionApp\LlmClient\Controllers\MessageController;
use ClarionApp\LlmClient\Controllers\LanguageModelController;
use ClarionApp\LlmClient\Controllers\AgentController;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Controllers\DeclarativeMemoryController;
use ClarionApp\LlmClient\Controllers\FetchPageController;
use ClarionApp\LlmClient\Controllers\McpServerController;
use ClarionApp\LlmClient\Controllers\EpisodicMemoryController;
use ClarionApp\LlmClient\Controllers\FeedbackController;
use ClarionApp\LlmClient\Controllers\RoleAssignmentController;
use ClarionApp\LlmClient\Controllers\ServerStatusController;
use ClarionApp\LlmClient\Controllers\RunController;
use ClarionApp\LlmClient\Controllers\ModelPriceController;
use ClarionApp\LlmClient\Controllers\CostRollupController;
use ClarionApp\LlmClient\Controllers\UsageRecordController;
use ClarionApp\LlmClient\Controllers\LatencyController;
use ClarionApp\LlmClient\Controllers\ToolReliabilityController;
use ClarionApp\LlmClient\Controllers\BudgetCeilingController;
use ClarionApp\LlmClient\Controllers\BudgetStandingController;
use ClarionApp\LlmClient\Controllers\RateLimitController;
use ClarionApp\LlmClient\Controllers\ConversationWorkCeilingController;
use ClarionApp\LlmClient\Controllers\ReductionStepController;
use ClarionApp\LlmClient\Controllers\DegradationStatusController;
use ClarionApp\LlmClient\Controllers\EvalSuiteController;
use ClarionApp\LlmClient\Controllers\EvalCaseController;
use ClarionApp\LlmClient\Controllers\EvalSuiteExportController;
use ClarionApp\LlmClient\Controllers\EvalRunController;
use ClarionApp\LlmClient\Controllers\EvalJudgmentController;
use ClarionApp\LlmClient\Controllers\EvalReferenceController;
use ClarionApp\LlmClient\Controllers\EvalRunComparisonController;
use ClarionApp\LlmClient\Controllers\EvalDashboardController;
use ClarionApp\LlmClient\Controllers\StoredAgentController;
use ClarionApp\LlmClient\Controllers\AgentVersionComparisonController;

Route::group(['middleware'=>'auth:api', 'prefix'=>$this->routePrefix ], function () {
    Route::resource('conversation', ConversationController::class);
    Route::post('conversation/{id}/generate-title', [ConversationController::class, "generateTitle"]);
    Route::post('conversation/{id}/end', [ConversationController::class, "end"]);
    Route::post('conversation/{id}/confirm-api-call', [ConversationController::class, "confirmApiCall"]);
    Route::get('user/{id}/conversation', [ConversationController::class, "userConversations"]);
    Route::post('agent', AgentController::class);
    Route::resource('server', ServerController::class);
    Route::resource('message', MessageController::class);
    Route::get('conversation/{conversation_id}/message', [MessageController::class, "index"]);
    Route::get('server/{server_id}/model', [LanguageModelController::class, "index"]);
    Route::post('models/{server_id}/refresh', [LanguageModelController::class, "refresh"]);
    Route::get('model', [LanguageModelController::class, "index"]);

    Route::post('page/text', [FetchPageController::class, "getTextFromUrl"]);

    Route::post('mcp', [McpServerController::class, "handle"]);

    // Episodic Memory endpoints (US3 - Search Past Conversation Events)
    Route::get('episodic-memories', [EpisodicMemoryController::class, "index"]);
    Route::post('episodic-memories/search', [EpisodicMemoryController::class, "search"]);
    Route::patch('episodic-memories/{id}/protect', [EpisodicMemoryController::class, "protect"]);
    Route::delete('episodic-memories/{id}', [EpisodicMemoryController::class, "destroy"]);

    // Declarative Memory endpoints (user-driven CRUD, behind auth:api)
    Route::get('declarative-memories', [DeclarativeMemoryController::class, "index"]);
    Route::post('declarative-memories', [DeclarativeMemoryController::class, "store"]);
    Route::put('declarative-memories/{id}', [DeclarativeMemoryController::class, "update"]);
    Route::delete('declarative-memories/{id}', [DeclarativeMemoryController::class, "destroy"]);

    // Feedback endpoints (learned preferences from user feedback)
    Route::post('feedback', [FeedbackController::class, "store"]);
    Route::get('feedback/preferences/proposed', [FeedbackController::class, "proposed"]);
    Route::post('feedback/preferences/{pattern_key}/confirm', [FeedbackController::class, "confirm"]);
    Route::post('feedback/preferences/{pattern_key}/decline', [FeedbackController::class, "decline"]);
    Route::get('feedback/preferences/learned', [FeedbackController::class, "learned"]);
    Route::patch('feedback/preferences/{id}', [FeedbackController::class, "update"]);
    Route::delete('feedback/preferences/{id}', [FeedbackController::class, "destroy"]);
    Route::get('feedback/audit/{preference_id}', [FeedbackController::class, "audit"]);

    // Role Assignment endpoints (model roles: inference, embedding, image)
    Route::post('role-assignment/test', [RoleAssignmentController::class, "test"]);
    Route::get('role-assignment', [RoleAssignmentController::class, "show"]);
    Route::put('role-assignment', [RoleAssignmentController::class, "update"]);
    Route::delete('role-assignment', [RoleAssignmentController::class, "destroy"]);

    // Server Status endpoint (server status projection for all servers)
    Route::get('server-status', [ServerStatusController::class, "index"]);

    // Agent run execution graph list endpoint (070 US6)
    Route::get('agent-runs', [RunController::class, "index"]);
    // Agent run execution graph read endpoints (070 US1)
    Route::get('agent-runs/{runId}', [RunController::class, "show"]);
    Route::get('agent-runs/{runId}/steps', [RunController::class, "steps"]);
    Route::get('agent-runs/{runId}/steps/{stepId}/actions', [RunController::class, "stepActions"]);
    Route::get('agent-runs/{runId}/actions/{actionId}/children', [RunController::class, "actionChildren"]);
    // Agent run execution graph action-detail endpoint (070 US2)
    Route::get('agent-runs/{runId}/actions/{actionId}', [RunController::class, "actionDetail"]);

    // Model price configuration endpoints (073 US1) — operator-only
    Route::get('model-prices', [ModelPriceController::class, "index"]);
    Route::put('model-prices', [ModelPriceController::class, "store"]);

    // Per-record cost detail endpoint (073 US4)
    Route::get('usage-records/{id}', [UsageRecordController::class, "show"]);

    // Cost rollup endpoints (073 US2) — role-scoped per contracts/cost-api.md §3/§4
    Route::get('cost-rollups/conversations/{conversationId}', [CostRollupController::class, "conversationShow"]);
    Route::get('cost-rollups/conversations', [CostRollupController::class, "conversationIndex"]);
    Route::get('cost-rollups/users/{userId}', [CostRollupController::class, "userShow"]);
    Route::get('cost-rollups/users', [CostRollupController::class, "userIndex"]);
    Route::get('cost-rollups/agents/{agentId}', [CostRollupController::class, "agentShow"]);
    Route::get('cost-rollups/agents', [CostRollupController::class, "agentIndex"]);

    // Latency distribution endpoints (074 US2) — role-scoped per contracts/latency-api.md §1
    Route::get('latency/models/{model}', [LatencyController::class, "modelShow"]);
    Route::get('latency/models', [LatencyController::class, "modelIndex"]);
    Route::get('latency/agents/{agentId}', [LatencyController::class, "agentShow"]);
    Route::get('latency/agents', [LatencyController::class, "agentIndex"]);

    // Tool reliability rate summary endpoints (075 US1) — role-scoped per
    // contracts/tool-reliability-api.md §1-2
    Route::get('tool-reliability/tools/{toolName}', [ToolReliabilityController::class, "show"]);
    Route::get('tool-reliability/tools', [ToolReliabilityController::class, "index"]);
    // Per-agent breakdown for one tool (075 US2) — contracts/tool-reliability-api.md §3
    Route::get('tool-reliability/tools/{toolName}/agents', [ToolReliabilityController::class, "agentBreakdown"]);

    // Spending ceiling configuration — operator-only, and never itself
    // subject to budget enforcement, so raising or waiving a ceiling stays
    // reachable to an operator whom that ceiling has stopped.
    Route::get('budget/ceilings', [BudgetCeilingController::class, "index"]);
    Route::put('budget/ceilings/installation', [BudgetCeilingController::class, "putInstallation"]);
    Route::put('budget/ceilings/user-default', [BudgetCeilingController::class, "putUserDefault"]);
    Route::put('budget/ceilings/users/{userId}', [BudgetCeilingController::class, "putUser"]);
    Route::delete('budget/ceilings/installation', [BudgetCeilingController::class, "destroyInstallation"]);
    Route::delete('budget/ceilings/user-default', [BudgetCeilingController::class, "destroyUserDefault"]);
    Route::delete('budget/ceilings/users/{userId}', [BudgetCeilingController::class, "destroyUser"]);

    // "Where do I stand" — read-only, and deliberately taking no from/to:
    // standing is always the current period of each applicable ceiling,
    // resolved server-side, because a caller-chosen range would not be the
    // range enforcement measures over.
    Route::get('budget/standing', [BudgetStandingController::class, "self"]);
    Route::get('budget/standing/users/{userId}', [BudgetStandingController::class, "user"]);
    Route::get('budget/standing/installation', [BudgetStandingController::class, "installation"]);

    // Per-user rate limit configuration — operator-only, and never itself
    // subject to rate-limit enforcement, so raising or waiving a limit
    // stays reachable to an operator whom that limit has stopped.
    Route::get('rate-limits', [RateLimitController::class, "index"]);
    Route::put('rate-limits/user-default', [RateLimitController::class, "putUserDefault"]);
    Route::delete('rate-limits/user-default', [RateLimitController::class, "destroyUserDefault"]);
    Route::put('rate-limits/users/{userId}', [RateLimitController::class, "putUser"]);
    Route::delete('rate-limits/users/{userId}', [RateLimitController::class, "destroyUser"]);

    // Per-conversation work ceiling configuration — operator-only, and
    // never itself subject to conversation-work enforcement. Covers both
    // the conversation-default ceiling that applies to any conversation
    // with no override, and a specific conversation's own override
    // (raise, lower, or waive), through the identical operator gate.
    Route::get('conversation-work-ceilings', [ConversationWorkCeilingController::class, "index"]);
    Route::put('conversation-work-ceilings/conversation-default', [ConversationWorkCeilingController::class, "putConversationDefault"]);
    Route::delete('conversation-work-ceilings/conversation-default', [ConversationWorkCeilingController::class, "destroyConversationDefault"]);
    Route::put('conversation-work-ceilings/conversations/{conversationId}', [ConversationWorkCeilingController::class, "putConversation"]);
    Route::delete('conversation-work-ceilings/conversations/{conversationId}', [ConversationWorkCeilingController::class, "destroyConversation"]);

    // Operator-defined reduction ladder configuration (contracts §1, US3)
    // — never itself subject to any of the enforcement axes it governs,
    // and read fresh (no cache) by DegradationGate::evaluate() on every
    // admitted request, so a change here governs the very next request
    // with no restart or deployment (FR-011/SC-008).
    Route::get('reduction-steps', [ReductionStepController::class, "index"]);
    Route::put('reduction-steps', [ReductionStepController::class, "store"]);
    Route::put('reduction-steps/{id}', [ReductionStepController::class, "update"]);
    Route::delete('reduction-steps/{id}', [ReductionStepController::class, "destroy"]);

    // GET /degradation/status (contracts §2, US4) — a user's own live,
    // non-persisted "would a fresh request be reduced right now" check, no
    // operator gate (FR-007/SC-004).
    Route::get('degradation/status', [DegradationStatusController::class, "self"]);

    Route::get('agent-eval-suites', [EvalSuiteController::class, "index"]);
    Route::post('agent-eval-suites', [EvalSuiteController::class, "store"]);
    Route::get('agent-eval-suites/{suiteId}', [EvalSuiteController::class, "show"]);
    Route::post('agent-eval-suites/{suiteId}/cases', [EvalCaseController::class, "store"]);

    Route::put('agent-eval-suites/{suiteId}', [EvalSuiteController::class, "update"]);
    Route::delete('agent-eval-suites/{suiteId}', [EvalSuiteController::class, "destroy"]);
    Route::put('agent-eval-suites/{suiteId}/cases/{caseId}', [EvalCaseController::class, "update"]);
    Route::delete('agent-eval-suites/{suiteId}/cases/{caseId}', [EvalCaseController::class, "destroy"]);
    Route::get('agent-eval-suites/{suiteId}/cases/{caseId}/versions', [EvalCaseController::class, "versions"]);

    Route::get('agent-eval-suites/{suiteId}/export', [EvalSuiteExportController::class, "export"]);
    Route::post('agent-eval-suites/import', [EvalSuiteExportController::class, "import"]);

    Route::post('agent-eval-suites/{suiteId}/runs', [EvalRunController::class, "store"]);
    Route::get('agent-eval-suites/{suiteId}/runs', [EvalRunController::class, "index"]);
    Route::get('eval-runs/{runId}', [EvalRunController::class, "show"]);
    Route::get('eval-runs/{runId}/cases', [EvalRunController::class, "cases"]);
    Route::post('eval-runs/{runId}/resume', [EvalRunController::class, "resume"]);

    // Consistency checks (contracts/eval-judgments-api.md §3)
    Route::post('agent-eval-suites/{suiteId}/cases/{caseId}/consistency-checks', [EvalJudgmentController::class, "consistencyChecks"]);
    Route::get('agent-eval-suites/{suiteId}/cases/{caseId}/consistency-checks', [EvalJudgmentController::class, "listConsistencyChecks"]);

    // Judgment detail and override (contracts/eval-judgments-api.md §2)
    Route::get('eval-judgments/{judgmentId}', [EvalJudgmentController::class, "show"]);
    Route::post('eval-judgments/{judgmentId}/override', [EvalJudgmentController::class, "override"]);

    // Reference designation and audit history (contracts/eval-regression-api.md §2)
    Route::post('eval-runs/{runId}/reference', [EvalReferenceController::class, "designate"]);
    Route::get('agent-eval-suites/{suiteId}/reference', [EvalReferenceController::class, "current"]);
    Route::get('agent-eval-suites/{suiteId}/reference/history', [EvalReferenceController::class, "history"]);

    // Run comparison against a reference (contracts/eval-regression-api.md §3)
    Route::get('eval-runs/{runId}/comparison', [EvalRunComparisonController::class, "index"]);

    // Case-level comparison detail (contracts/eval-regression-api.md §4)
    Route::get('eval-runs/{runId}/comparison/cases/{evalCaseId}', [EvalRunComparisonController::class, "caseDetail"]);

    // Agent quality dashboard overview (contracts/eval-dashboard-api.md §1)
    Route::get('agent-eval-dashboard/{agentLabel}', [EvalDashboardController::class, "index"]);

    // Case detail composition — given/expected_behavior/produced_response/
    // judgment reasoning (contracts/eval-dashboard-api.md §2)
    Route::get('eval-runs/{runId}/cases/{caseResultId}/detail', [EvalDashboardController::class, "caseDetail"]);

    // Agent version history (contracts/agent-versioning-api.md §1/§4) — the
    // `agents` (plural) route group, deliberately distinct from the
    // pre-existing singular `Route::post('agent', AgentController::class)`
    // above (087-agent-model-versioning, Phase 3/US1).
    // On-demand check, before saving (088-agent-definition-validator,
    // contracts/agent-definition-validator-api.md §1) -- stateless, no
    // agent id, always 200. Placed before `agents`/`store` for readability
    // (no route-ordering ambiguity either way).
    Route::post('agents/check', [StoredAgentController::class, "check"]);

    Route::post('agents', [StoredAgentController::class, "store"]);
    Route::put('agents/{id}', [StoredAgentController::class, "update"]);

    // Agent version history — list/read/restore (contracts/agent-versioning-api.md
    // §2/§3/§5/§6/§7, 087-agent-model-versioning, Phase 4/US2).
    Route::get('agents', [StoredAgentController::class, "index"]);
    Route::get('agents/{id}', [StoredAgentController::class, "show"]);
    Route::get('agents/{id}/versions', [StoredAgentController::class, "versions"]);
    Route::get('agents/{id}/versions/{versionId}', [StoredAgentController::class, "versionDetail"]);
    Route::post('agents/{id}/versions/{versionId}/restore', [StoredAgentController::class, "restore"]);

    // Agent version history — link/unlink/divergence/sync-from-file
    // (contracts/agent-versioning-api.md §8/§9/§10/§11,
    // 087-agent-model-versioning, Phase 5/US3).
    Route::put('agents/{id}/link', [StoredAgentController::class, "link"]);
    Route::delete('agents/{id}/link', [StoredAgentController::class, "unlink"]);
    Route::get('agents/{id}/divergence', [StoredAgentController::class, "divergence"]);
    Route::post('agents/{id}/sync-from-file', [StoredAgentController::class, "syncFromFile"]);

    // Clone an agent into a complete, independent copy (091-agent-clone-fork,
    // contracts/agent-clone-api.md §1, Phase 3/US1).
    Route::post('agents/{id}/clone', [StoredAgentController::class, "clone"]);

    // Compare two agent versions (090-agent-version-binding, Phase 5/US3,
    // contracts §4/§5). Named independently by two version ids, not nested
    // under a single agent (research.md D7) — no collision with the
    // `agents/{id}/...` routes above (see contracts §5's own segment-count
    // argument).
    Route::get('agents/versions/compare', [AgentVersionComparisonController::class, "compare"]);
});

Broadcast::channel('Conversation.{id}', function ($user, $id) {
    $conversation = Conversation::find($id);
    if(!$conversation) return false;

    if($conversation->user_id === $user->id) return true;

    return false;
});

