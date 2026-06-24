<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Conversation;
use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Support\Carbon;

class McpResourceHandler
{
    /**
     * Callable for URL validation.
     * Defaults to UrlValidator::validate when not overridden.
     */
    protected $validator;

    /**
     * Set a custom URL validator (useful for testing).
     */
    public function setValidator(callable $validator): self
    {
        $this->validator = $validator;
        return $this;
    }

    /**
     * Validate a URL using the configured validator.
     */
    protected function validateUrl(string $url): array
    {
        $validator = $this->validator ?? [UrlValidator::class, 'validate'];
        return $validator($url);
    }

    public function listResources(string $userId, ?string $cursor = null): array
    {
        $offset = 0;
        if ($cursor) {
            $decoded = base64_decode($cursor, true);
            if ($decoded !== false) {
                $cursorData = json_decode($decoded, true);
                $offset = $cursorData['offset'] ?? 0;
            }
        }

        $pageSize = config('llm-client.mcp.page_size', 50);

        $conversations = Conversation::query()
            ->where('user_id', $userId)
            ->withCount('messages')
            ->orderBy('updated_at', 'desc')
            ->skip($offset)
            ->take($pageSize + 1)
            ->get();

        $hasMore = $conversations->count() > $pageSize;
        $page = $hasMore ? $conversations->slice(0, $pageSize) : $conversations;

        $nextCursor = null;
        if ($hasMore) {
            $nextCursor = base64_encode(json_encode(['offset' => $offset + $pageSize]));
        }

        $resources = [];
        foreach ($page as $conversation) {
            $messageCount = $conversation->messages_count ?? 0;
            $updatedAt = $conversation->updated_at;
            $timeAgo = $updatedAt ? Carbon::parse($updatedAt)->diffForHumans() : 'unknown';

            $resources[] = [
                'uri' => "conversation://{$conversation->id}",
                'name' => $conversation->title ?: 'Untitled Conversation',
                'description' => "Conversation with {$messageCount} messages, last active {$timeAgo}",
                'mimeType' => 'application/json',
            ];
        }

        return [
            'resources' => $resources,
            'nextCursor' => $nextCursor,
        ];
    }

    public function readResource(string $userId, string $uri): array
    {
        $scheme = parse_url($uri, PHP_URL_SCHEME);

        switch ($scheme) {
            case 'conversation':
                return $this->readConversationResource($userId, $uri);
            case 'page':
                return $this->readPageResource($uri);
            default:
                return [
                    'error' => [
                        'code' => -32002,
                        'message' => 'Unsupported resource URI scheme',
                        'data' => ['uri' => $uri],
                    ],
                ];
        }
    }

    public function listResourceTemplates(?string $cursor = null): array
    {
        return [
            'resourceTemplates' => [
                [
                    'uriTemplate' => 'page://{url}',
                    'name' => 'Web Page Text',
                    'description' => 'Fetch and extract text content from a web page URL',
                    'mimeType' => 'text/plain',
                ],
            ],
            'nextCursor' => null,
        ];
    }

    private function readConversationResource(string $userId, string $uri): array
    {
        // Parse cursor from URI query string if present
        $parsedUrl = parse_url($uri);
        $cursor = null;
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
            $cursor = $queryParams['cursor'] ?? null;
        }

        // Extract conversation UUID from URI host
        $conversationId = $parsedUrl['host'] ?? null;
        if (!$conversationId) {
            return [
                'error' => [
                    'code' => -32002,
                    'message' => 'Resource not found',
                    'data' => ['uri' => $uri],
                ],
            ];
        }

        $conversation = Conversation::where('id', $conversationId)->first();

        if (!$conversation) {
            return [
                'error' => [
                    'code' => -32002,
                    'message' => 'Resource not found',
                    'data' => ['uri' => $uri],
                ],
            ];
        }

        if ($conversation->user_id !== $userId) {
            return [
                'error' => [
                    'code' => -32002,
                    'message' => 'Resource not found',
                    'data' => ['uri' => $uri],
                ],
            ];
        }

        $messagesPageSize = config('llm-client.mcp.messages_page_size', 100);
        $offset = 0;
        if ($cursor) {
            $decoded = base64_decode($cursor, true);
            if ($decoded !== false) {
                $cursorData = json_decode($decoded, true);
                $offset = $cursorData['offset'] ?? 0;
            }
        }

        $messages = $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->skip($offset)
            ->take($messagesPageSize + 1)
            ->get();

        $hasMore = $messages->count() > $messagesPageSize;
        $page = $hasMore ? $messages->slice(0, $messagesPageSize) : $messages;

        $nextCursor = null;
        if ($hasMore) {
            $nextCursor = base64_encode(json_encode(['offset' => $offset + $messagesPageSize]));
        }

        $formattedMessages = [];
        foreach ($page as $message) {
            $formattedMessages[] = [
                'role' => $message->role,
                'content' => $message->content,
                'timestamp' => $message->created_at,
            ];
        }

        $data = [
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'model' => $conversation->model,
                'channel' => $conversation->channel,
            ],
            'messages' => $formattedMessages,
            'pagination' => [
                'total' => $conversation->messages_count ?? count($formattedMessages),
                'returned' => count($formattedMessages),
                'nextCursor' => $nextCursor,
            ],
        ];

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'application/json',
                    'text' => json_encode($data),
                ],
            ],
        ];
    }

    private function readPageResource(string $uri): array
    {
        // Extract URL from page:// URI — everything after "page://"
        $url = substr($uri, strlen('page://'));

        if (empty($url)) {
            return [
                'error' => [
                    'code' => -32602,
                    'message' => 'Invalid resource URI',
                    'data' => ['uri' => $uri],
                ],
            ];
        }

        $validation = $this->validateUrl($url);
        if (!$validation['valid']) {
            return [
                'error' => [
                    'code' => -32602,
                    'message' => 'URL validation failed',
                    'data' => ['reason' => $validation['reason']],
                ],
            ];
        }

        try {
            $text = $this->fetchPageText($url);
        } catch (\Exception $e) {
            return [
                'error' => [
                    'code' => -32603,
                    'message' => 'Internal error',
                ],
            ];
        }

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'text/plain',
                    'text' => $text,
                ],
            ],
        ];
    }

    public function fetchPageText(string $url): string
    {
        $host = 'http://localhost:9515';
        $agent = "Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/111.0";
        $chromeOptions = new ChromeOptions();
        $chromeOptions->addArguments(['--headless']);
        $chromeOptions->addArguments(['--user-agent=' . $agent]);
        $capabilities = \Facebook\WebDriver\Remote\DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY_W3C, $chromeOptions);
        $driver = ChromeDriver::start($capabilities);

        try {
            $driver->get($url);
            $text = $driver->findElement(WebDriverBy::tagName('body'))->getText();
        } finally {
            $driver->quit();
        }

        return $text;
    }
}
