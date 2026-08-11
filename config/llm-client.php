<?php

return [
    // Routes blocked from LLM execution (matched with fnmatch())
    'api_denylist' => [
        '/api/clarion-app/llm-client/*',
        '/api/clarion/system/*',
        '/api/clarion-app/multichain/*',
    ],

    // HTTP methods that require user confirmation before execution
    'confirm_methods' => ['DELETE'],

    'ssrf' => [
        'max_redirects' => 5,
    ],

    // Agent Loop configuration
    'agent_loop' => [
        'max_iterations' => 20,
        'confirmation_timeout' => 300,
        'max_tools' => 128,
        'system_prompt' => 'You are Clarion, a concise home automation assistant. You discover and execute API operations using meta-tools: search_operations, execute_operation, list_applications, and memory management tools.'.PHP_EOL.
        'Tool Selection Rules:'.PHP_EOL.
        '0. For known operations (listed in the Known Operations section): call execute_operation directly with the matching operationId and parameters — skip search_operations. This is preferred over search to reduce latency. If the request could match multiple known operations, ask the user to clarify which one they mean.'.PHP_EOL.
        '1. If no known operation matches, use search_operations with a natural language query describing the intent. Review results, then call execute_operation with the matching operationId and parameters.'.PHP_EOL.
        '2. For broad discovery queries (e.g., "what can I do?", "what\'s available?"): call list_applications to return available applications and summarize their capabilities.'.PHP_EOL.
        '3. For multi-operation requests (e.g., "find a contact and send them a message"): perform sequential search-then-execute cycles — search for the first operation, execute it, then search for the next operation, execute it, and so on.'.PHP_EOL.
        'Memory Management (memory_create, memory_read, memory_search, memory_delete):'.PHP_EOL.
        '- Three scopes available: scratch, short_term, long_term.'.PHP_EOL.
        '- scratch: Ephemeral working memory, automatically discarded after each turn. Use for intermediate computation, temporary state, or notes within a single LLM call.'.PHP_EOL.
        '- short_term: Persists across turns within a conversation session. Use to track conversation state, user preferences for this session, or accumulate context across turns. Automatically cleared when the session ends.'.PHP_EOL.
        '- long_term: Persists across conversation sessions. Use for facts about the user, learned preferences, or important references. Subject to LRU eviction (configurable limit). Use sparingly for truly persistent data.'.PHP_EOL.
        '- When creating entries, use descriptive keys (max 64 chars) for direct lookup, or omit key for auto-generated UUIDs.'.PHP_EOL.
        '- Use memory_search with mode "key_prefix" for prefix matching on keys, "content" for full-text search within content, or "semantic" for meaning-based search (long_term scope only, returns results with similarity_score 0.0-1.0)'.PHP_EOL.
        'Recovery Rules:'.PHP_EOL.
        '- If search_operations returns no results: try broader search terms once, then fall back to list_applications.'.PHP_EOL.
        '- If results don\'t match intent: retry search_operations once with rephrased broader terms, then fall back to list_applications.'.PHP_EOL.
        '- If the search index is unavailable or empty (hint in response): inform the user and use list_applications as an alternative.'.PHP_EOL.
        'Response Style: After successfully executing tool calls, do not summarize what you did, do not list details like IP addresses or parameters, and do not offer follow-up suggestions. Only respond if there was an error or if the user asked a question.'.PHP_EOL.
        'Parameter Format: execute_operation takes an operationId and a parameters object with optional "path", "query", and "body" sub-objects. Put each parameter in the group the operation\'s schema assigns it to — never pass parameters as a flat object.'.PHP_EOL.
        'Example (direct execution for known operations):'.PHP_EOL.
        '- User: "add a contact named Alice"'.PHP_EOL.
        '- Agent: (contacts.store is in Known Operations)'.PHP_EOL.
        '- Agent: execute_operation("contacts.store", {body: {name: "Alice"}})'.PHP_EOL.
        'Example (search-then-execute flow):'.PHP_EOL.
        '- User: "add a contact named Alice"'.PHP_EOL.
        '- Agent: search_operations("add contact")'.PHP_EOL.
        '- Agent: reviews results, selects best matching operationId, reads its parameter schema'.PHP_EOL.
        '- Agent: execute_operation(operationId, {body: {name: "Alice"}})'.PHP_EOL.
        'Example (path and query parameters):'.PHP_EOL.
        '- User: "show me contact 42 with their address"'.PHP_EOL.
        '- Agent: execute_operation("contacts.show", {path: {id: "42"}, query: {include: "address"}})'.PHP_EOL.
        'Example (capability discovery):'.PHP_EOL.
        '- User: "what can I do?"'.PHP_EOL.
        '- Agent: list_applications()'.PHP_EOL.
        '- Agent: summarizes available capabilities based on application descriptions',
    ],

    // Conversation settings
    'conversation' => [
        'inactivity_threshold_hours' => 4,
    ],

    // MCP Server configuration
    'mcp' => [
        // Supported MCP protocol versions
        'supported_versions' => ['2025-03-26'],

        // Session time-to-live in minutes (sessions inactive longer than this may be expired)
        'session_ttl' => 60,

        // Default page size for tools/list pagination
        'page_size' => 50,

        // Default page size for messages in resources/read responses
        'messages_page_size' => 100,

        // Confirmation token expiry in seconds
        'confirmation_token_expiry' => 300,
    ],

    // Operations Search configuration
    'operations_search' => [
        'default_limit' => 10,    // Maximum results returned by search
    ],

    // Operation Cache configuration
    // Deployment note: the shared-storage guarantee is only as good as the
    // configured store — 'array' and per-container 'file' stores are NOT shared
    // across workers and will silently reproduce the original process-local defect.
    // Only 'database' (or another genuinely shared store) fixes it.
    'operation_cache' => [
        'max_entries' => 20,    // Max cached operations per conversation (LRU eviction)
        'store'       => null,  // Cache store name; null = application default
        'ttl'         => 86400, // Seconds; 24h, refreshed on every write
        'lock_seconds' => 5,    // Lock hold time
        'lock_wait'    => 3,    // Max block before falling through unsynchronized
    ],

    // Memory configuration
    'memory' => [
        'long_term_max_entries' => 200,  // Max long-term entries per agent (LRU eviction)
        'search_default_limit' => 20,    // Default max results for memory search
        'search_max_limit' => 100,       // Hard cap on search results

        // Embedding configuration for semantic search
        'embedding' => [
            'server_id' => null,        // UUID of Server record for embedding provider (null = use chat provider if supported)
            'dimension' => 1536,        // Vector dimension (must match embedding model output, default 1536 for text-embedding-3-small)
            'model' => null,            // Optional: override embedding model name (e.g., 'text-embedding-3-small')
            'enabled' => true,          // Master toggle: disable embedding generation entirely
        ],
    ],

    // Conversation lifecycle — when a conversation session is considered over.
    //
    // A session end is NOT the end of an agent response: the agent answering a
    // message means the turn finished, not that the user is done. Ending a session
    // triggers short-term memory cleanup and episodic capture, so treating every
    // response as an end wipes session memory each turn and captures an episodic
    // record of only the opening exchange.
    'conversation_lifecycle' => [
        // Minutes of inactivity after which `llm-client:end-idle-conversations`
        // treats a conversation as ended. Run that command on a schedule.
        'idle_timeout_minutes' => env('LLM_CLIENT_CONVERSATION_IDLE_MINUTES', 30),
    ],

    // Episodic Memory configuration
    'episodic_memory' => [
        'retention_days' => 90,                // Default retention period in days
        'cleanup_schedule' => 'daily',         // Cleanup job schedule
        'max_topics_per_entry' => 10,          // Maximum topic tags per entry
        'summary_max_ratio' => 0.20,           // Summary must be ≤ 20% of original word count
        'summarization_timeout_seconds' => 120, // Job timeout for summarization
    ],

    // Declarative Memory configuration — permanent, user-scoped facts/preferences/rules.
    // NOTE: intentionally has NO retention, eviction, or entry-cap settings.
    'declarative_memory' => [
        // Normalized cosine-similarity threshold (0.0–1.0) above which a new confirmed
        // entry is treated as conflicting with an existing same-type entry and supersedes it (FR-010).
        'conflict_similarity_threshold' => 0.85,
    ],

    // Structured Output Presets configuration
    'presets' => [
        // Which built-in presets to register (all enabled by default)
        'enabled' => ['decision', 'summary', 'extraction'],
    ],

    // Schema validation for structured (JSON-mode) responses.
    // Validation only runs when a caller passes a schema; these settings govern
    // the retry loop that feeds violation details back to the LLM for self-correction.
    'schema_validation' => [
        // Times to re-prompt with a correction message before giving up.
        // Bounded to avoid looping on a model that cannot satisfy the schema.
        // Per-request override: $options['max_schema_retries'].
        'max_retries' => 2,
    ],

    // Per-provider defaults
    'providers' => [
        'openai' => [
            'default_model' => env('LLM_OPENAI_DEFAULT_MODEL', 'gpt-4o'),
            'timeout' => env('LLM_OPENAI_TIMEOUT', 240),
        ],
        'anthropic' => [
            'default_model' => env('LLM_ANTHROPIC_DEFAULT_MODEL', 'claude-sonnet-4-20250514'),
            // Anthropic's API version header. '2023-06-01' is the current release;
            // it is not a "latest" date — do not bump it to today's date.
            'api_version' => env('LLM_ANTHROPIC_API_VERSION', '2023-06-01'),
            'timeout' => env('LLM_ANTHROPIC_TIMEOUT', 240),
        ],
        'llama.cpp' => [
            'default_model' => env('LLM_LLAMA_CPP_DEFAULT_MODEL', null),
            'timeout' => env('LLM_LLAMA_CPP_TIMEOUT', 240),
        ],
    ],

    // Context window budgeting — sliding window with token budgeting.
    // Keeps agent requests under the model's accepted input size regardless
    // of how long the stored history grows.
    'context_window' => [
        // Master toggle. When false, the budgeter is a pass-through (no trimming).
        'enabled' => true,

        // Fractional safety margin subtracted from raw context to absorb
        // character-based estimation error (0.0–1.0).
        // 15% headroom compensates for the inaccuracy of strlen-based token estimation.
        'headroom_ratio' => env('LLM_CONTEXT_HEADROOM_RATIO', 0.15),

        // Tokens reserved for same-budget injected content that is NOT part of the
        // pinned system message measured directly (e.g. the preset system prompt
        // appended after formatMessages() in run(); growth room for Known Operations,
        // Episodic/Declarative memory, preferences).
        // 1500 tokens covers a typical preset system prompt (~500) plus memory sections (~1000).
        'injected_section_reserve' => 1500,

        // Known models: exact model name → capacity + response reserve (tokens).
        // Values sourced from provider published limits; response_reserve is a fixed
        // per-model default independent of caller-supplied max_tokens.
        'models' => [
            // OpenAI: gpt-4o has a 128K context window; 4K response reserve for long answers.
            'gpt-4o'                     => ['context' => 128000, 'response_reserve' => 4096],
            // Anthropic: Claude Sonnet 4 has a 200K context window; 8K response reserve.
            'claude-sonnet-4-20250514'   => ['context' => 200000, 'response_reserve' => 8192],
            // Small local model for testing capacity adaptation (US3).
            'llama3-8b'                  => ['context' => 8192, 'response_reserve' => 2048],
        ],

        // Per-provider defaults, used when the specific model is absent from 'models'.
        // OpenAI fallback: conservative 8K (covers older models like text-davinci-003).
        'providers' => [
            'openai'    => ['context' => 8192,   'response_reserve' => 2048],
            'anthropic' => ['context' => 200000, 'response_reserve' => 8192],
            'llama.cpp' => ['context' => 8192,   'response_reserve' => 2048],
        ],

        // Conservative global fallback when neither model nor provider is configured.
        // 8K context with 2K reserve — safe for most modern models.
        'fallback' => ['context' => 8192, 'response_reserve' => 2048],
    ],

    // Conversation condensation — replaces dropped older messages with cached per-chunk summaries.
    // Composed in front of the ContextWindowBudgeter so trimming remains the fallback.
    'condensation' => [
        // Master toggle. When false, condensation is skipped and the budgeter trims normally.
        'enabled' => true,

        // Fixed chunk size in turn-units. The older portion is partitioned into chunks of this size
        // by message ordinal (floor(ordinal / chunk_size)). Each chunk is summarized exactly once.
        'chunk_size' => 20,

        // Condensation model name. Null → use the conversation's effective model.
        // Set to a cheaper model to reduce condensation cost.
        'model' => null,

        // Condensation provider type. Null → use the conversation's effective provider.
        'provider' => null,

        // Timeout in seconds for synchronous first-touch condensation.
        // If the condensation call exceeds this, the request falls back to trimming.
        'timeout_seconds' => 20,

        // Number of consecutive condensation failures before entering cooldown.
        'failure_threshold' => 3,

        // Cooldown duration in seconds. While in cooldown, condensation is skipped entirely.
        'cooldown_seconds' => 300,

        // When true, opportunistically dispatch a queued pre-warm job when a chunk seals.
        // The synchronous path remains the guarantee when the pre-warm hasn't landed.
        'prewarm' => true,
    ],

    // Tool result condensation — intercepts oversized tool results before they enter agent context.
    // Applies deterministic structure-aware reduction for JSON and LLM summarization for prose.
    'tool_result_condensation' => [
        // Master toggle. When false, all tool results pass through unchanged.
        'enabled' => true,

        // Token threshold: results at or below this size pass through without condensation.
        'threshold_tokens' => 2000,

        // Hard cap on condensed output size in tokens.
        'max_condensed_tokens' => 500,

        // Number of sample items to preserve in array reduction.
        'sample_items' => 5,

        // Timeout in seconds for LLM-based prose summarization.
        'summarization_timeout_seconds' => 5,

        // TTL for full-result cache entries in minutes.
        'cache_ttl_minutes' => 240,
    ],

    // Smart history trimming — value-aware eviction that discards lowest-value content first
    // when conversation history must shrink to fit the model's context window.
    'smart_history_trimming' => [
        // Master toggle. When false, smart trimming is skipped entirely.
        'enabled' => true,

        // Minimum number of recent message pairs to always preserve (exempt from eviction).
        'preserved_pairs' => 10,

        // Score cache TTL in minutes.
        'score_cache_ttl_minutes' => 5,

        // Whether to emit SmartHistoryTrimmed events.
        'emit_events' => true,
    ],

    // Learning Preferences — feedback extraction and preference learning pipeline.
    // Accumulates transient feedback signals, extracts implied preference patterns
    // via LLM inference (deferred/queued), and proposes learned preferences for
    // user confirmation through the DeclarativeMemory confirmation gate.
    'learning_preferences' => [
        // Number of consistent signals required before proposing a learned preference.
        'promotion_threshold' => 5,

        // Amount to reduce the effective count when a contradictory signal is detected.
        'contradiction_decay' => 2,

        // Maximum number of pending signals to process in a single extraction job run.
        'extraction_batch_size' => 20,

        // Whether to use LLM inference for pattern extraction (false = heuristic-only).
        'llm_enabled' => true,

        // Number of days to retain processed feedback signals before purging.
        'signal_retention_days' => 30,
    ],

    // Agent Preferences Injection — assembles stored user preferences and binding rules
    // into a bounded text block for injection into the agent system prompt on every turn.
    'preferences_injection' => [
        // Master toggle. When false, preference injection is skipped entirely.
        'enabled' => true,

        // Token budget for the entire assembled block (headers included).
        // Token estimation uses strlen() / 4, consistent with ContextWindowBudgeter.
        'max_tokens' => 500,
    ],

    // Context Management Metrics — captures context utilization and mechanism activation
    // telemetry for every LLM request. Recording is fire-and-forget at the
    // applyContextWindowTrim() boundary; failures are logged and never block requests.
    'context_management_metrics' => [
        // Master toggle. When false, context management recording is skipped entirely.
        'enabled' => true,

        // Number of days to retain detail records and conversation summaries.
        // User summaries are lifetime rollups and are never purged.
        'retention_days' => 90,
    ],

    // Auto Memory Retrieval — automatically retrieves relevant memories from multiple
    // memory stores (declarative, episodic, long-term) based on the current user input
    // and injects them into the agent context before each LLM call.
    'auto_memory_retrieval' => [
        // Master toggle. When false, auto-retrieval is skipped entirely.
        'enabled' => true,

        // Token budget for the entire injected memory text block.
        // Prevents retrieved memories from consuming excessive context space.
        // 1000 tokens × 4 chars/token = 4000 chars hard limit.
        'max_tokens' => 1000,

        // Minimum cosine similarity (0.0–1.0) for a memory to be included in results.
        // Lower values cast a wider net; higher values are more selective.
        'relevance_threshold' => 0.3,

        // Maximum number of entries to retrieve per memory kind (declarative, episodic, etc.).
        // Rules are exempt from this cap.
        'max_results_per_store' => 5,

        // Timeout in milliseconds for embedding generation during retrieval.
        // This is the only real interrupt in the synchronous pipeline.
        'embedding_timeout_ms' => 500,

        // Hard budget in milliseconds for the entire retrieval pipeline.
        // Enforced as a pre-stage gate: a store that has not started yet is
        // skipped once the budget is spent. A store already in flight runs to
        // completion — synchronous PHP cannot abort it.
        'timeout_ms' => 2000,

        // Which memory stores to query during auto-retrieval.
        // Options: 'declarative', 'episodic', 'long-term'.
        'stores' => ['declarative', 'episodic', 'long-term'],
    ],

    // Agent Run Trace — records a step-by-step trace of how each agent response
    // was produced. Every agent response (synchronous or streamed) and every
    // background model-driven job produces a run record with ordered steps.
    // Recording is fire-and-forget: if the record cannot be written, the
    // response still proceeds normally and the failure is logged.
    'run_trace' => [
        // Master toggle. When false, all recording is skipped entirely.
        'enabled' => true,

        // Number of days to retain run trace records.
        // Matches context_management_metrics.retention_days for a single purge policy.
        'retention_days' => 90,

        // Minutes of inactivity before an in-progress run is considered abandoned.
        // 60 minutes is conservative against false abandonment of slow queued work
        // and comfortably exceeds the 300-second confirmation timeout.
        'abandonment_minutes' => 60,

        // Per-action content cap in bytes. Content exceeding this is truncated
        // at write time to prevent a single large tool result from bloating storage.
        'action_content_cap_bytes' => env('LLM_CLIENT_RUN_TRACE_ACTION_CONTENT_CAP_BYTES', 16384),

        // Maximum action execution time in minutes. Actions exceeding this threshold
        // are marked 'unfinished' when the run closes.
        'action_timeout_minutes' => env('LLM_CLIENT_RUN_TRACE_ACTION_TIMEOUT_MINUTES', 5),

        // Maximum number of action rows per run. When exceeded, openAction() returns
        // null (no-op) and logs a warning, but the agent loop continues normally.
        'action_row_cap' => env('LLM_CLIENT_RUN_TRACE_ACTION_ROW_CAP', 500),

        // Patterns for credential and secret redaction in action content.
        'redaction_patterns' => [
            'headers' => ['authorization', 'x-api-key', 'proxy-authorization'],
            'json_fields' => ['password', 'secret', 'token', 'api_key', 'access_key', 'private_key'],
            'url_params' => ['access_token', 'api_key', 'password', 'secret'],
            'token_prefixes' => ['sk-', 'ghp_', 'gho_', 'ghu_', 'ghs_'],
        ],

        // Where records go. Any non-empty subset of ['internal', 'external'].
        // Invalid, empty, or absent -> falls back to ['internal'], logged once
        // per process (FR-004, FR-013-equivalent for this field).
        'export' => [
            'destinations' => explode(',', env('LLM_CLIENT_TRACE_EXPORT_DESTINATIONS', 'internal')),

            // OTLP/HTTP endpoint, e.g. 'https://tempo.example.com:4318/v1/traces'.
            // Required (and validated as an http(s) URL) only when 'external' is selected.
            'otlp_endpoint' => env('LLM_CLIENT_TRACE_EXPORT_ENDPOINT'),

            // Header name + value carrying the destination credential. Never logged,
            // never persisted to any table. Excluded from every debug/array
            // representation this feature produces.
            'otlp_auth_header' => env('LLM_CLIENT_TRACE_EXPORT_AUTH_HEADER', 'Authorization'),
            'otlp_auth_value' => env('LLM_CLIENT_TRACE_EXPORT_AUTH_VALUE'),

            // Forwarding buffer bound, record count.
            'buffer_max_records' => (int) env('LLM_CLIENT_TRACE_EXPORT_BUFFER_MAX', 10000),

            // Delivery retry bound and backoff shape.
            'max_attempts' => (int) env('LLM_CLIENT_TRACE_EXPORT_MAX_ATTEMPTS', 3),
            'retry_base_seconds' => (int) env('LLM_CLIENT_TRACE_EXPORT_RETRY_BASE_SECONDS', 30),
            'retry_max_seconds' => (int) env('LLM_CLIENT_TRACE_EXPORT_RETRY_MAX_SECONDS', 900),

            // Per-request HTTP client timeout. Only ever consulted by the scheduled
            // delivery command -- never on the request/response path.
            'http_timeout_seconds' => (int) env('LLM_CLIENT_TRACE_EXPORT_HTTP_TIMEOUT_SECONDS', 10),

            // Per-scheduler-tick delivery batch cap.
            'max_records_per_run' => (int) env('LLM_CLIENT_TRACE_EXPORT_MAX_RECORDS_PER_RUN', 100),

            // A record whose built payload exceeds this many bytes is discarded
            // without an HTTP attempt.
            'max_payload_bytes' => (int) env('LLM_CLIENT_TRACE_EXPORT_MAX_PAYLOAD_BYTES', 65536),
        ],
    ],

    // Usage cost rollups — per-model pricing and cost attribution across
    // conversations, users, and agents.
    'cost' => [
        // Currency label attached to cost-related API responses as metadata
        // only. No conversion, no multi-currency storage (FR-018).
        'currency' => env('LLM_CLIENT_COST_CURRENCY', 'USD'),

        // Config-driven operator allow-list (research.md D4). User UUID
        // strings permitted to configure prices (FR-017) and see unrestricted
        // cross-user rollups (FR-021). Comma-separated in the env var.
        'operator_user_ids' => array_values(array_filter(
            explode(',', env('LLM_CLIENT_COST_OPERATOR_USER_IDS', '')),
            fn ($id) => $id !== ''
        )),
    ],

    // Spending ceilings — how enforcement behaves at the edges. Note there
    // is deliberately NO master on/off toggle here: "off" already means "no
    // ceiling configured", and a second way to be off would be a second
    // competing notion of whether a limit applies. Currency and operator
    // identity are not redeclared either — they reuse the 'cost' section
    // above, so this feature adds no second permission tier.
    'budget' => [
        // What to do when the consumption figure cannot be read at all.
        // 'stop' = fail-closed (the default), 'allow' = fail-open. This
        // bites only where a ceiling is configured in 'stop' mode: a
        // warn-only ceiling never blocks, and an installation with no
        // ceiling configured never reaches the ledger in the first place.
        'on_unreadable_consumption' => env('LLM_CLIENT_BUDGET_ON_UNREADABLE_CONSUMPTION', 'stop'),

        // Proportion of a ceiling's amount at which the approach warning
        // fires, used when a ceiling does not name its own. Exactly one
        // threshold per ceiling.
        'default_approach_threshold' => 0.80,

        // Seconds between repeat degraded-enforcement broadcasts while the
        // degraded condition persists. Every occurrence is still logged.
        'degraded_notice_throttle_seconds' => env('LLM_CLIENT_BUDGET_DEGRADED_THROTTLE', 60),
    ],

    // Agent behavior test suite definitions — bounds applied both at
    // authoring time (EvalCaseService/EvalSuiteService) and on import
    // (EvalSuiteImporter, where the document may originate outside the
    // installation and is untrusted input). One rule set, not two: a case
    // an operator could create by hand is exactly the set of cases an
    // import can recreate. No master on/off toggle — there is no "off"
    // question for eval suites the way there is for budget enforcement.
    'eval_suites' => [
        'max_cases_per_suite' => 200,
        'max_expectations_per_case' => 20,
        'max_text_length' => 10000,       // given / expected_behavior / expectation text fields
        'max_identifier_length' => 255,   // name / agent_identifier

        // schema_version values this installation's importer accepts. A
        // version outside this set is rejected with a clear reason rather
        // than guessed at.
        'supported_export_schema_versions' => [1],
    ],

    // Response latency distributions — per-model and per-agent percentile
    // figures computed at read time from agent_runs.
    'latency' => [
        // The percentile reported as "worst-case" in a distribution. "Typical"
        // is always the median (p50) and is not separately configurable.
        'worst_case_percentile' => env('LLM_CLIENT_LATENCY_WORST_CASE_PERCENTILE', 95),
    ],
];

