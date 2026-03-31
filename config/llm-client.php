<?php

return [
    // Routes blocked from LLM execution (matched with fnmatch())
    'api_denylist' => [
        '/api/clarion-app/llm-client/*',
        '/api/clarion/system/*',
        '/api/clarion-app/multichain/*',
    ],

    // HTTP methods that require user confirmation before execution
    'confirm_methods' => ['PUT', 'PATCH', 'DELETE'],

    'ssrf' => [
        'max_redirects' => 5,
    ],
];
