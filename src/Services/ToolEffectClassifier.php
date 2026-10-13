<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\ToolEffect;

/**
 * Classifies a tool call's effect class at the start-marker write point,
 * so a resume knows whether a call may be re-run freely, is recorded and
 * reused, or may be re-run at most once. The rules are evaluated in order
 * and the first match wins; a lookup that fails classifies effectful
 * (the safe default) rather than throwing or falling back to effect-free.
 */
class ToolEffectClassifier
{
    /** The delegation and question tools: their work is recorded, not re-run. */
    private const DURABLE_TOOLS = ['delegate_to_helper', 'assign_part', 'ask_user'];

    /** The built-in read tools: safe to re-run freely. */
    private const EFFECT_FREE_BUILTINS = ['search_operations', 'list_applications', 'memory_read', 'memory_search'];

    /** The HTTP methods that are safe to repeat. */
    private const SAFE_METHODS = ['GET', 'HEAD'];

    public function classify(string $tool, array $arguments, Conversation $conversation): ToolEffect
    {
        // The delegation and question tools are durable: their outcome is
        // recorded and reused on a resume, never silently repeated.
        if (in_array($tool, self::DURABLE_TOOLS, true)) {
            return ToolEffect::Durable;
        }

        if ($tool === 'execute_operation') {
            $operationId = (string) ($arguments['operationId'] ?? '');
            if ($operationId === '') {
                return ToolEffect::Effectful;
            }

            // A capability offering is durable: the call is recorded and
            // reused, so the offered agent's work is not repeated.
            try {
                if (CapabilityOffering::where('id', $operationId)->exists()) {
                    return ToolEffect::Durable;
                }
            } catch (\Throwable) {
                return ToolEffect::Effectful;
            }

            // An external mcp: tool is effectful: its readOnlyHint is
            // untrusted, so it is never treated as a safe read.
            if (str_starts_with($operationId, 'mcp:')) {
                return ToolEffect::Effectful;
            }

            // A host operation is classified by its HTTP method: a GET or
            // HEAD is a safe read, anything else (or an id that resolves to
            // nothing) is effectful.
            try {
                $details = ApiManager::getOperationDetails($operationId);
            } catch (\Throwable) {
                return ToolEffect::Effectful;
            }
            if (is_array($details) && isset($details['method'])) {
                $method = strtoupper((string) $details['method']);
                if (in_array($method, self::SAFE_METHODS, true)) {
                    return ToolEffect::EffectFree;
                }

                return ToolEffect::Effectful;
            }

            return ToolEffect::Effectful;
        }

        // A registry tool that declares a safe method in its metadata is a
        // read; a non-safe (or absent) method falls through to the named
        // built-ins and the operator list.
        try {
            $entry = app(McpToolRegistry::class)->findTool($tool);
        } catch (\Throwable) {
            return ToolEffect::Effectful;
        }
        if (is_array($entry) && isset($entry['_meta']['method'])) {
            $method = strtoupper((string) $entry['_meta']['method']);
            if (in_array($method, self::SAFE_METHODS, true)) {
                return ToolEffect::EffectFree;
            }
        }

        if (in_array($tool, self::EFFECT_FREE_BUILTINS, true)) {
            return ToolEffect::EffectFree;
        }

        $effectFree = (array) config('llm-client.resume.effect_free_tools', []);
        if (in_array($tool, $effectFree, true)) {
            return ToolEffect::EffectFree;
        }

        return ToolEffect::Effectful;
    }
}
