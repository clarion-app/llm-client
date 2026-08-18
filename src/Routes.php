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
use ClarionApp\LlmClient\Controllers\AgentShareController;
use ClarionApp\LlmClient\Controllers\AgentHelperController;
use ClarionApp\LlmClient\Controllers\CapabilityOfferingController;
use ClarionApp\LlmClient\Controllers\DelegationController;
use ClarionApp\LlmClient\Controllers\ManagedTaskController;
use ClarionApp\LlmClient\Controllers\ConsensusController;
use ClarionApp\LlmClient\Controllers\SequenceController;
use ClarionApp\LlmClient\Controllers\CodingProjectController;
use ClarionApp\LlmClient\Controllers\CodingWorkspaceController;
use ClarionApp\LlmClient\Controllers\SchedulerTriggerController;
use ClarionApp\LlmClient\Controllers\AgentStartingPointController;
use ClarionApp\LlmClient\Controllers\McpClientServerController;

Route::group(['middleware'=>'auth:api', 'prefix'=>$this->routePrefix ], function () {
    Route::resource('conversation', ConversationController::class);
    Route::post('conversation/{id}/generate-title', [ConversationController::class, "generateTitle"]);
    Route::post('conversation/{id}/end', [ConversationController::class, "end"]);
    Route::post('conversation/{id}/confirm-api-call', [ConversationController::class, "confirmApiCall"]);
    Route::get('conversation/{id}/handoffs', [ConversationController::class, "handoffs"]);
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
    // Multi-agent arrangement read endpoint (106 US1)
    Route::get('agent-runs/{runId}/arrangement', [RunController::class, "arrangement"]);

    // Delegation protocol read endpoints (098 US3)
    Route::get('agent-runs/{runId}/delegations', [DelegationController::class, "forRun"]);
    Route::get('delegations/{id}', [DelegationController::class, "show"]);
    Route::get('agent-runs/{runId}/cost-with-delegations', [DelegationController::class, "cost"]);
    // Result aggregation read endpoint (099 US3)
    Route::get('agent-runs/{runId}/combined-results', [DelegationController::class, "combinedResults"]);

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

    // Ready-made agent starting points -- browse and create-from.
    // Additive-only: neither route changes agents/check, agents (POST),
    // or agents/{id} (PUT) above in any way.
    Route::get('agent-starting-points', [AgentStartingPointController::class, "index"]);
    Route::post('agent-starting-points/{slug}', [AgentStartingPointController::class, "store"]);

    // Agent version history — list/read/restore (contracts/agent-versioning-api.md
    // §2/§3/§5/§6/§7, 087-agent-model-versioning, Phase 4/US2).
    Route::get('agents', [StoredAgentController::class, "index"]);

    // Free-text search/browse (094-agent-search-listing, contracts/
    // agent-search-api.md §1) -- registered before the `agents/{id}`
    // wildcard below so a literal "search" is never swallowed as an id.
    Route::get('agents/search', [StoredAgentController::class, "search"]);

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

    // Activate/deactivate an agent (092-agent-activation,
    // contracts/agent-activation-api.md §1/§2, Phase 3/US1).
    Route::post('agents/{id}/activate', [StoredAgentController::class, "activate"]);
    Route::post('agents/{id}/deactivate', [StoredAgentController::class, "deactivate"]);

    // Set/clear an agent as the caller's default handler (102-router-pattern,
    // contracts/routing-mechanism.md §4, Phase 6/US4).
    Route::post('agents/{id}/default-handler', [StoredAgentController::class, "setDefaultHandler"]);
    Route::delete('agents/{id}/default-handler', [StoredAgentController::class, "clearDefaultHandler"]);

    // Share an agent with another installation user, and list who currently
    // has access (096-agent-sharing, contracts/agent-sharing-api.md §1/§2,
    // Phase 3/US1). Both owner-only.
    Route::post('agents/{id}/shares', [AgentShareController::class, "share"]);
    Route::get('agents/{id}/shares', [AgentShareController::class, "shares"]);

    // Revoke a previously granted share (096-agent-sharing,
    // contracts/agent-sharing-api.md §3, Phase 5/US3). Owner-only.
    Route::delete('agents/{id}/shares/{recipientUserId}', [AgentShareController::class, "unshare"]);

    // Assign a helper to an agent, and list its currently-active helpers
    // (097-subagent-model, contracts/subagent-model-api.md §1/§2,
    // Phase 3/US1+US2). Both owner-only. A helper's own permitted
    // operations are refused if they exceed the parent's (US2); cycle/
    // depth-limit protection and the hierarchy/remove endpoints are added
    // in later phases.
    Route::post('agents/{id}/helpers', [AgentHelperController::class, "assign"]);
    Route::get('agents/{id}/helpers', [AgentHelperController::class, "helpers"]);

    // Full descendant graph beneath an agent's own helpers, not only the
    // immediate ones the route above lists (097-subagent-model, Phase
    // 4/US3, contracts/subagent-model-api.md §3, FR-007). Owner-only.
    Route::get('agents/{id}/helpers/hierarchy', [AgentHelperController::class, "hierarchy"]);

    // Remove a previously assigned helper (097-subagent-model, Phase 5/US4,
    // contracts/subagent-model-api.md §4). Owner-only for the parent side;
    // idempotent — always 204 whether an active assignment existed or not.
    Route::delete('agents/{id}/helpers/{helperAgentId}', [AgentHelperController::class, "remove"]);

    // Offer an agent as a capability to another, list what an agent
    // currently offers, and withdraw an offering (109-agent-as-capability,
    // Phase 2/Foundational, contracts/capability-offering-api.md). All
    // owner-only for the offered-agent side, mirroring the helper-
    // assignment routes above exactly. Idempotent DELETE — 200
    // {"removed": bool}, never a bare 204 (distinct from the helper-
    // removal route's own posture, per this feature's own contract).
    Route::post('agents/{offeredAgentId}/capability-offerings', [CapabilityOfferingController::class, "offer"]);
    Route::get('agents/{offeredAgentId}/capability-offerings', [CapabilityOfferingController::class, "list"]);
    Route::delete('agents/{offeredAgentId}/capability-offerings/{callerAgentId}', [CapabilityOfferingController::class, "withdraw"]);

    // Compare two agent versions (090-agent-version-binding, Phase 5/US3,
    // contracts §4/§5). Named independently by two version ids, not nested
    // under a single agent (research.md D7) — no collision with the
    // `agents/{id}/...` routes above (see contracts §5's own segment-count
    // argument).
    Route::get('agents/versions/compare', [AgentVersionComparisonController::class, "compare"]);

    // Manager agent -- start a managed task, and read its status/outcome
    // (103-manager-agent, US1, contracts/manager-agent-api.md §1/§2).
    // Every subsequent write happens through the manager's own meta-tools,
    // reached only from inside its agent loop, never directly over HTTP
    // (research.md D6).
    Route::post('managed-tasks', [ManagedTaskController::class, "store"]);
    Route::get('managed-tasks/{id}', [ManagedTaskController::class, "show"]);
    Route::get('managed-tasks/{id}/parts', [ManagedTaskController::class, "parts"]);
    Route::get('managed-tasks/{id}/cost', [ManagedTaskController::class, "cost"]);
    // Shared task workspace -- read-only human/test-facing visibility
    // into a task's shared working area (108-shared-task-workspace,
    // contracts/task-workspace-api.md §1). Writes happen only through
    // the record_task_note meta-tool, reached from inside an agent loop.
    Route::get('managed-tasks/{id}/workspace', [ManagedTaskController::class, "workspace"]);

    // Multi-agent consensus -- ask a question with multi-opinion mode
    // enabled, and read back a past request's stored result
    // (104-multi-agent-consensus, US1, contracts/consensus-api.md §1/§2).
    // Synchronous end-to-end (research.md D5): store() does not return
    // until a terminal ConsensusRequest.status is reached, mirroring the
    // existing POST /agent endpoint's own blocking shape rather than
    // managed-tasks' 202.
    Route::post('consensus-requests', [ConsensusController::class, "store"]);
    Route::get('consensus-requests/{id}', [ConsensusController::class, "show"]);
    Route::post('consensus-requests/cost-estimate', [ConsensusController::class, "estimateCost"]);
    // Individual contributor answers, for every terminal status (Phase
    // 6/US4, contracts/consensus-api.md §3, FR-008).
    Route::get('consensus-requests/{id}/contributors', [ConsensusController::class, "contributors"]);

    // Stage Pipeline -- define a sequence once, run it repeatedly
    // (105-stage-pipeline, contracts/stage-pipeline-api.md §1-§5). store()
    // is the only endpoint that creates a StageSequenceDefinition;
    // storeRun() is the only endpoint that creates a SequenceRun. Every
    // write here is thin -- it creates/updates the durable row(s),
    // dispatches exactly one RunSequenceStageJob, and returns immediately
    // (research.md D8) -- it never holds the HTTP request open for a
    // stage's own execution. Phase 2 (Foundational): every method below
    // is still a 501 stub, filled in across Phases 3 (US1) and 6 (US4).
    Route::post('sequence-definitions', [SequenceController::class, "store"]);
    Route::get('sequence-definitions', [SequenceController::class, "index"]);
    Route::get('sequence-definitions/{id}', [SequenceController::class, "show"]);
    Route::post('sequence-definitions/{id}/runs', [SequenceController::class, "storeRun"]);
    Route::get('sequence-runs/{id}', [SequenceController::class, "showRun"]);
    Route::post('sequence-runs/{id}/resume', [SequenceController::class, "resume"]);

    // Coding Agent (112-coding-agent, contracts/coding-workspace-operations.md
    // §0-§3). CodingProjectController is human-driven registration only,
    // never named in coding.yaml's tools.allow. CodingWorkspaceController
    // is the agent-callable read/write/test/git surface, every route
    // scoped to a single {project} and cross-checked against
    // $conversation->coding_project_id at the AgentLoopService seam
    // before any of these methods run (data-model.md §4). Phase 2
    // (Foundational): CodingProjectController is fully implemented;
    // CodingWorkspaceController's methods are still 501 placeholders,
    // filled in across Phase 3 (US1).
    Route::post('coding-project', [CodingProjectController::class, "store"]);
    Route::get('coding-project', [CodingProjectController::class, "index"]);
    Route::delete('coding-project/{id}', [CodingProjectController::class, "destroy"]);
    Route::get('coding-project/{project}/files', [CodingWorkspaceController::class, "listFiles"]);
    Route::get('coding-project/{project}/file', [CodingWorkspaceController::class, "readFile"]);
    Route::post('coding-project/{project}/file', [CodingWorkspaceController::class, "writeFile"]);
    Route::delete('coding-project/{project}/file', [CodingWorkspaceController::class, "deleteFile"]);
    Route::get('coding-project/{project}/git-status', [CodingWorkspaceController::class, "gitStatus"]);
    Route::get('coding-project/{project}/git-diff', [CodingWorkspaceController::class, "gitDiff"]);
    Route::post('coding-project/{project}/run-tests', [CodingWorkspaceController::class, "runTests"]);

    // Scheduler Agent -- human-driven trigger registration, CRUD scoped
    // entirely to the caller's own triggers.
    // Never named in scheduler.yaml's own tools.allow; a trigger is
    // configured by a person, run only by RunSchedulerTriggerJob, and read
    // (its produced runs) only through the existing RunController.
    Route::post('scheduler-triggers', [SchedulerTriggerController::class, "store"]);
    Route::get('scheduler-triggers', [SchedulerTriggerController::class, "index"]);
    Route::get('scheduler-triggers/{id}', [SchedulerTriggerController::class, "show"]);
    Route::put('scheduler-triggers/{id}', [SchedulerTriggerController::class, "update"]);
    Route::delete('scheduler-triggers/{id}', [SchedulerTriggerController::class, "destroy"]);

    // External MCP server configuration -- ownership resolved through
    // McpClientServer::eligibleFor() (the same personal-or-installation-
    // scope predicate RoleAssignment resolution already applies), with
    // absent/foreign-owned servers 404ing the same uniform way
    // RunController's own read endpoints already do.
    Route::get('mcp-client-server', [McpClientServerController::class, "index"]);
    Route::post('mcp-client-server', [McpClientServerController::class, "store"]);

    // Registered before the {id}-parameterized routes below so the
    // literal "test-connection" segment is never captured as {id} --
    // both new routes here are unambiguous by segment count alone (the
    // POST route has one segment where store() has zero and refresh()'s
    // {id}/refresh has two; the GET route has two segments where show()
    // has one), but registration order still matches this controller's
    // own established literal-before-parameterized convention and is
    // verified directly by McpClientServerConnectionTestScopeTest.
    Route::post('mcp-client-server/test-connection', [McpClientServerController::class, "testConnection"]);
    Route::get('mcp-client-server/test-connection/{id}', [McpClientServerController::class, "showTestConnection"]);

    Route::get('mcp-client-server/{id}', [McpClientServerController::class, "show"]);
    Route::delete('mcp-client-server/{id}', [McpClientServerController::class, "destroy"]);
    Route::post('mcp-client-server/{id}/refresh', [McpClientServerController::class, "refresh"]);
    Route::patch('mcp-client-server/{id}/credential', [McpClientServerController::class, "replaceCredential"]);
});

Broadcast::channel('Conversation.{id}', function ($user, $id) {
    $conversation = Conversation::find($id);
    if(!$conversation) return false;

    if($conversation->user_id === $user->id) return true;

    return false;
});

