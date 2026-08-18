<?php

namespace ClarionApp\LlmClient\Services;

/**
 * The CallValidatorInterface implementation for a synthesized external-
 * tool operationId. Reuses the identical fnmatch()-over-
 * config('llm-client.api_denylist') check ApiCallValidator::validate()
 * already performs for a built-in route's own resolved path, applied
 * here to the synthesized "/mcp-client/{server_id}/{tool_name}" path
 * instead -- the same configured rules, the same array, no parallel
 * denylist.
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
     * @param string $operationId The synthetic "mcp:{server_id}:{tool_name}" id -- carried only because the interface's own signature requires it; not read by this decision.
     * @param string $method The fixed "MCP_EXTERNAL" sentinel -- not read by this decision.
     * @param string $path The synthesized "/mcp-client/{server_id}/{tool_name}" path checked against the denylist.
     * @return array{status: string, reason?: string}
     */
    public function validate(string $operationId, string $method, string $path): array
    {
        $denylist = config('llm-client.api_denylist', []);
        $normalizedPath = '/' . ltrim($path, '/');

        foreach ($denylist as $pattern) {
            if (fnmatch($pattern, $normalizedPath)) {
                return [
                    'status' => ApiCallValidator::STATUS_REJECT,
                    'reason' => "Path is denylisted: {$normalizedPath}",
                ];
            }
        }

        return [
            'status' => ApiCallValidator::STATUS_CONFIRM,
            'reason' => 'External tool calls always require confirmation',
        ];
    }
}
