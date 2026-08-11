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
use ClarionApp\LlmClient\Controllers\EvalSuiteController;
use ClarionApp\LlmClient\Controllers\EvalCaseController;
use ClarionApp\LlmClient\Controllers\EvalSuiteExportController;
use ClarionApp\LlmClient\Controllers\EvalRunController;

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
});

Broadcast::channel('Conversation.{id}', function ($user, $id) {
    $conversation = Conversation::find($id);
    if(!$conversation) return false;

    if($conversation->user_id === $user->id) return true;

    return false;
});

