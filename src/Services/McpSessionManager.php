<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\McpSession;

class McpSessionManager
{
    public function createSession(
        string $userId,
        string $protocolVersion,
        ?string $clientName = null,
        ?string $clientVersion = null,
        ?array $capabilities = null
    ): McpSession {
        return McpSession::create([
            'user_id' => $userId,
            'protocol_version' => $protocolVersion,
            'client_name' => $clientName,
            'client_version' => $clientVersion,
            'capabilities' => $capabilities,
        ]);
    }

    public function validateSession(string $sessionId, string $userId): ?McpSession
    {
        return McpSession::where('id', $sessionId)
            ->where('user_id', $userId)
            ->first();
    }

    public function terminateSession(string $sessionId, string $userId): bool
    {
        $session = $this->validateSession($sessionId, $userId);
        if (!$session) {
            return false;
        }

        $session->delete();
        return true;
    }

    public function touchSession(string $sessionId): void
    {
        $session = McpSession::find($sessionId);
        if ($session) {
            $session->touch();
        }
    }
}
