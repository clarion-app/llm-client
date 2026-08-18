<?php

namespace ClarionApp\LlmClient\Services;

/**
 * The CallValidatorInterface implementation for a synthesized external-
 * tool operationId. Delegates the denylist check to DenylistMatcher::
 * matchesAny() -- the same shared matching primitive
 * ApiCallValidator::validate() calls for a built-in route's own resolved
 * path -- checked here against two candidates: the synthesized
 * "/mcp-client/{server_id}/{tool_name}" path (backward compatible with a
 * rule written before the tool's own durable id existed) and the tool's
 * own operationId (rename-durable, since a server can rewrite its own
 * tool's name but never this installation's locally-generated id). A
 * match on either candidate rejects the call.
 *
 * Every non-denied call returns STATUS_CONFIRM unconditionally: the safe
 * universal default for an action whose actual destructiveness this
 * class has no trustworthy way to determine ahead of time.
 *
 * Deliberately takes no McpClientTool argument anywhere in this method's
 * signature, and reads nothing beyond the three plain strings the
 * interface itself defines -- there is no parameter here a tool's own
 * name, description, or annotations could ever reach, so no future edit
 * to this method's body could let server-supplied text influence the
 * confirm/deny decision without also changing the method's own contract.
 */
class McpClientCallValidator implements CallValidatorInterface
{
    /**
     * @param string $operationId The synthetic "mcp:{server_id}:{tool_id}" id -- checked against the denylist as a second candidate alongside the resolved path; a match on either rejects the call.
     * @param string $method The fixed "MCP_EXTERNAL" sentinel -- not read by this decision.
     * @param string $path The synthesized "/mcp-client/{server_id}/{tool_name}" path checked against the denylist.
     * @return array{status: string, reason?: string}
     */
    public function validate(string $operationId, string $method, string $path): array
    {
        $denylist = config('llm-client.api_denylist', []);
        $normalizedPath = '/' . ltrim($path, '/');

        $matchedPattern = DenylistMatcher::matchesAny($denylist, $normalizedPath, $operationId);
        if ($matchedPattern !== null) {
            return [
                'status' => ApiCallValidator::STATUS_REJECT,
                'reason' => "Denylisted: {$matchedPattern}",
            ];
        }

        return [
            'status' => ApiCallValidator::STATUS_CONFIRM,
            'reason' => 'External tool calls always require confirmation',
        ];
    }
}
