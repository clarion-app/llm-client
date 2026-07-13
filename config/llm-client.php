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
        'Example (direct execution for known operations):'.PHP_EOL.
        '- User: "add a contact named Alice"'.PHP_EOL.
        '- Agent: (contacts.store is in Known Operations)'.PHP_EOL.
        '- Agent: execute_operation("contacts.store", {name: "Alice"})'.PHP_EOL.
        'Example (search-then-execute flow):'.PHP_EOL.
        '- User: "add a contact named Alice"'.PHP_EOL.
        '- Agent: search_operations("add contact")'.PHP_EOL.
        '- Agent: reviews results, selects best matching operationId'.PHP_EOL.
        '- Agent: execute_operation(operationId, {name: "Alice"})'.PHP_EOL.
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
    'operation_cache' => [
        'max_entries' => 20,    // Max cached operations per conversation (LRU eviction)
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

    // Episodic Memory configuration
    'episodic_memory' => [
        'retention_days' => 90,                // Default retention period in days
        'cleanup_schedule' => 'daily',         // Cleanup job schedule
        'max_topics_per_entry' => 10,          // Maximum topic tags per entry
        'summary_max_ratio' => 0.20,           // Summary must be ≤ 20% of original word count
        'summarization_timeout_seconds' => 120, // Job timeout for summarization
    ],

    // Structured Output Presets configuration
    'presets' => [
        // Which built-in presets to register (all enabled by default)
        'enabled' => ['decision', 'summary', 'extraction'],
    ],

    // Per-provider defaults
    'providers' => [
        'openai' => [
            'default_model' => env('LLM_OPENAI_DEFAULT_MODEL', 'gpt-4o'),
            'timeout' => env('LLM_OPENAI_TIMEOUT', 240),
        ],
        'anthropic' => [
            'default_model' => env('LLM_ANTHROPIC_DEFAULT_MODEL', 'claude-sonnet-4-20250514'),
            'api_version' => env('LLM_ANTHROPIC_API_VERSION', '2025-04-14'),
            'timeout' => env('LLM_ANTHROPIC_TIMEOUT', 240),
        ],
        'llama.cpp' => [
            'default_model' => env('LLM_LLAMA_CPP_DEFAULT_MODEL', null),
            'timeout' => env('LLM_LLAMA_CPP_TIMEOUT', 240),
        ],
    ],
];

