<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\McpSession;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use Carbon\Carbon;

class AgentLoopService
{
    private McpToolRegistry $toolRegistry;
    private McpToolExecutor $toolExecutor;

    public function __construct(McpToolRegistry $toolRegistry, McpToolExecutor $toolExecutor)
    {
        $this->toolRegistry = $toolRegistry;
        $this->toolExecutor = $toolExecutor;
    }

    public function start(Conversation $conversation, int $iteration = 1): void
    {
        $conversation->update(['is_processing' => true]);

        $tools = $this->buildToolsPayload();
        $messages = $this->buildMessagesPayload($conversation);

        $this->dispatchStreamRequest($conversation, $messages, $tools, $iteration);
    }

    public function resume(Conversation $conversation, Message $message, bool $approved): void
    {
        $toolData = $message->tool_data;
        $pending = $toolData['pending_confirmation'] ?? null;

        if (!$pending) {
            throw new \RuntimeException('No pending confirmation found on this message.');
        }

        // Check for expiration
        $expiresAt = Carbon::parse($pending['expires_at']);
        if ($expiresAt->isPast()) {
            $conversation->update(['is_processing' => false]);
            throw new \RuntimeException('Confirmation has expired.');
        }

        $toolCallId = $toolData['tool_calls'][0]['id'] ?? null;
        $iteration = ($toolData['iteration'] ?? 1) + 1;

        if ($approved) {
            // Execute the tool
            $session = $this->getOrCreateSession($conversation);
            $result = $this->toolExecutor->executeTool(
                $pending['tool_name'],
                $pending['arguments'],
                $session
            );

            $resultContent = $this->extractResultContent($result);

            $toolData['tool_results'] = [
                ['tool_call_id' => $toolCallId, 'content' => $resultContent],
            ];
        } else {
            $toolData['tool_results'] = [
                ['tool_call_id' => $toolCallId, 'content' => 'User cancelled this operation.'],
            ];
        }

        $toolData['pending_confirmation'] = null;
        $message->update(['tool_data' => $toolData]);

        // Continue the agent loop
        $tools = $this->buildToolsPayload();
        $messages = $this->buildMessagesPayload($conversation);
        $this->dispatchStreamRequest($conversation, $messages, $tools, $iteration);
    }

    public function buildToolsPayload(): array
    {
        $allTools = [];
        $cursor = null;

        do {
            $result = $this->toolRegistry->getTools($cursor);
            foreach ($result['tools'] as $tool) {
                $allTools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'parameters' => $tool['inputSchema'],
                    ],
                ];
            }
            $cursor = $result['nextCursor'];
        } while ($cursor !== null);

        return $allTools;
    }

    public function buildMessagesPayload(Conversation $conversation): array
    {
        $dbMessages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get();

        $payload = [];

        foreach ($dbMessages as $msg) {
            if ($msg->tool_data && !empty($msg->tool_data['tool_calls'])) {
                // Assistant message with tool calls
                $assistantMsg = [
                    'role' => 'assistant',
                    'content' => $msg->content ?: null,
                    'tool_calls' => $msg->tool_data['tool_calls'],
                ];
                $payload[] = $assistantMsg;

                // Tool result messages
                if (!empty($msg->tool_data['tool_results'])) {
                    foreach ($msg->tool_data['tool_results'] as $result) {
                        $payload[] = [
                            'role' => 'tool',
                            'tool_call_id' => $result['tool_call_id'],
                            'content' => $result['content'],
                        ];
                    }
                }
            } else {
                // Regular message (user, assistant text, system)
                $payload[] = [
                    'role' => strtolower($msg->role),
                    'content' => $msg->content,
                ];
            }
        }

        return $payload;
    }

    private function dispatchStreamRequest(Conversation $conversation, array $messages, array $tools, int $iteration): void
    {
        $server = Server::find($conversation->server_id);

        $body = new \stdClass();
        $body->temperature = 1.0;
        $body->model = $conversation->model;
        $body->stream = true;
        $body->messages = $messages;

        if (!empty($tools)) {
            $body->tools = $tools;
        }

        $request = new HttpRequest();
        $request->url = $server->server_url;
        $request->method = "POST";
        $request->headers = [
            'Content-type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $server->token,
        ];
        $request->body = $body;

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => $iteration,
        ]);

        SendHttpStreamRequest::dispatch(
            $request,
            "ClarionApp\\LlmClient\\AgentLoopStreamHandler",
            $data
        );
    }

    private function getOrCreateSession(Conversation $conversation): McpSession
    {
        $session = McpSession::where('user_id', $conversation->user_id)->first();
        if (!$session) {
            $session = McpSession::create([
                'user_id' => $conversation->user_id,
                'protocol_version' => '2025-03-26',
            ]);
        }
        return $session;
    }

    private function extractResultContent(array $result): string
    {
        if (!empty($result['content'])) {
            return $result['content'][0]['text'] ?? json_encode($result['content']);
        }
        return json_encode($result);
    }
}
