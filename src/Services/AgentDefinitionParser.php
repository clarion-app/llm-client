<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\ValueObjects\AgentDefinition;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionCollectionResult;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionParseErrorKind;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionResolutionErrorKind;
use ClarionApp\LlmClient\ValueObjects\MemoryKind;
use ClarionApp\LlmClient\ValueObjects\OperationGroupPattern;
use ClarionApp\LlmClient\ValueObjects\ReducibleTool;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses and fully resolves a single agent definition YAML document into an
 * AgentDefinition (086-agent-yaml-schema, contracts/agent-definition-parser.md
 * §1). The sole entry point that ever constructs an AgentDefinition.
 *
 * Fixed check order (tasks.md Grounding note 2 / research.md D11):
 *   0. YAML parse — malformed or non-mapping root -> MalformedYaml
 *   1. format_version -> UnrecognizedFormatVersion
 *   2. full structural unknown-key scan (top-level + memory/tools/safety nested keys) -> UnknownKey
 *   3. name -> MissingName
 *   4. instructions -> InstructionsTooLong
 *   5. model -> UnknownModel
 *   6. capabilities -> UnknownCapability
 *   7. memory (key + enabled/disabled value) -> UnknownKey
 *   8. tools.allow / tools.deny -> EmptyOperationPattern (author-written patterns only)
 *   9. safety.confirmation_required -> EmptyOperationPattern (bare verbs exempt)
 *  10. safety.denylist -> EmptyOperationPattern (bare verbs exempt)
 *  11. safety.unattended_authorized -> EmptyOperationPattern (bare verbs exempt)
 *
 * collect() is the sole implementation of the 12-step rule set, in
 * collecting form: it never throws for a content-level problem, instead
 * appending every one it finds (including every repeated occurrence within
 * a single step) to a returned list and continuing through every remaining
 * step regardless of what an earlier step found. parse() is a thin wrapper
 * over collect() that throws the first collected problem, if any, else
 * returns the collected definition — identical signature, exception types,
 * and single-error behavior as before. Performs no writes of any kind.
 *
 * Scope note: this parser populates AgentDefinition's own fields only. It
 * does not read config('llm-client.api_denylist') or
 * config('llm-client.confirm_methods') — those installation-ceiling checks
 * live inside AgentDefinition::isOperationPermitted()/isConfirmationRequired()
 * themselves, and a document's own destructive-operation warning is
 * computed by a separate service, AgentDefinitionValidator, not here.
 */
final class AgentDefinitionParser
{
    private const HTTP_VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private const TOP_LEVEL_KEYS = [
        'format_version', 'name', 'version', 'instructions', 'model',
        'memory', 'capabilities', 'tools', 'safety',
    ];

    private const TOOLS_KEYS = ['allow', 'deny'];

    private const SAFETY_KEYS = ['confirmation_required', 'denylist', 'unattended_authorized'];

    public function parse(string $rawYaml): AgentDefinition
    {
        $result = $this->collect($rawYaml);

        if ($result->problems !== []) {
            throw $result->problems[0];
        }

        return $result->definition;
    }

    /**
     * The sole implementation of the 12-step rule set (fixed check order
     * above, unchanged), in collecting form: every problem a step finds is
     * appended to the returned result's problems list instead of being
     * thrown, and every step still runs regardless of whether an earlier
     * one already found a problem — except the single YAML-structural
     * precondition (step 0), which short-circuits with exactly one problem
     * since there is no further content to check.
     *
     * Only AgentDefinitionParseException/AgentDefinitionResolutionException
     * are ever caught here. Any other \Throwable (e.g. a genuine database
     * failure resolving the model, or underlying the operation catalog)
     * propagates uncaught — never appears in problems.
     */
    public function collect(string $rawYaml): AgentDefinitionCollectionResult
    {
        try {
            $document = $this->parseYaml($rawYaml);
        } catch (AgentDefinitionParseException $e) {
            return new AgentDefinitionCollectionResult(
                definition: $this->placeholderDefinition(),
                problems: [$e],
                catalog: [],
            );
        }

        $problems = [];

        try {
            $formatVersion = $this->resolveFormatVersion($document);
        } catch (AgentDefinitionParseException $e) {
            $problems[] = $e;
            $formatVersion = (string) config('llm-client.agent_definitions.current_format_version');
        }

        $this->scanForUnknownKeys($document, $problems);

        try {
            $name = $this->resolveName($document);
        } catch (AgentDefinitionParseException $e) {
            $problems[] = $e;
            $name = '';
        }

        try {
            $instructions = $this->resolveInstructions($document);
        } catch (AgentDefinitionParseException $e) {
            $problems[] = $e;
            $instructions = '';
        }

        try {
            $model = $this->resolveModel($document);
        } catch (AgentDefinitionResolutionException $e) {
            $problems[] = $e;
            $model = null;
        }

        $capabilities = $this->resolveCapabilities($document, $problems);
        $memory = $this->resolveMemory($document, $problems);

        // Resolved once per collect() call and reused across every pattern
        // check below (steps 8-10) — never re-fetched per pattern — and by
        // AgentDefinitionValidator's own warning computation, which reuses
        // this exact array rather than resolving its own.
        $catalog = $this->resolveCatalog();

        [$toolsAllow, $toolsDeny] = $this->resolveTools($document, $catalog, $problems);
        $safetyConfirmationRequired = $this->resolveSafetyList($document, 'confirmation_required', $catalog, $problems);
        $safetyDenylist = $this->resolveSafetyList($document, 'denylist', $catalog, $problems);
        $unattendedAuthorized = $this->resolveSafetyList($document, 'unattended_authorized', $catalog, $problems);

        $version = $document['version'] ?? null;
        $version = $version !== null ? (string) $version : null;

        $definition = new AgentDefinition(
            formatVersion: $formatVersion,
            name: $name,
            version: $version,
            instructions: $instructions,
            model: $model,
            memory: $memory,
            capabilities: $capabilities,
            toolsAllow: $toolsAllow,
            toolsDeny: $toolsDeny,
            safetyConfirmationRequired: $safetyConfirmationRequired,
            safetyDenylist: $safetyDenylist,
            unattendedAuthorized: $unattendedAuthorized,
        );

        return new AgentDefinitionCollectionResult(
            definition: $definition,
            problems: $problems,
            catalog: $catalog,
        );
    }

    /**
     * A harmless, fully-defaulted AgentDefinition used only when the
     * document fails the YAML-structural precondition (step 0) — no
     * further content could be checked, so every field is its own
     * ordinary default rather than a value derived from the document.
     */
    private function placeholderDefinition(): AgentDefinition
    {
        return new AgentDefinition(
            formatVersion: (string) config('llm-client.agent_definitions.current_format_version'),
            name: '',
            version: null,
            instructions: '',
            model: null,
            memory: $this->defaultMemory(),
            capabilities: ReducibleTool::cases(),
            toolsAllow: ['*'],
            toolsDeny: [],
            safetyConfirmationRequired: [],
            safetyDenylist: [],
            unattendedAuthorized: [],
        );
    }

    /**
     * @return array<string, bool>
     */
    private function defaultMemory(): array
    {
        return array_fill_keys(
            array_map(static fn (MemoryKind $kind): string => $kind->value, MemoryKind::cases()),
            true,
        );
    }

    /**
     * Step 0: YAML parse. A thrown ParseException, a genuinely non-mapping
     * root (a bare scalar, or a non-empty list), is MalformedYaml — a
     * precondition of reading anything, not itself a numbered step in the
     * check order. An empty or whitespace-only document, and an explicit
     * "{}"/"[]" (both parse to PHP's own empty array — indistinguishable
     * from each other), are deliberately *not* MalformedYaml: they fall
     * through as an empty mapping so the ordinary per-key checks run,
     * where the next step's own MissingName naturally fires — a different,
     * more specific kind for "there is nothing here" than for "this is
     * broken."
     *
     * @return array<string, mixed>
     */
    private function parseYaml(string $rawYaml): array
    {
        try {
            $parsed = Yaml::parse($rawYaml);
        } catch (ParseException $e) {
            throw new AgentDefinitionParseException(
                AgentDefinitionParseErrorKind::MalformedYaml,
                value: $e->getMessage(),
                previous: $e,
            );
        }

        if ($parsed === null) {
            return [];
        }

        if (!is_array($parsed)) {
            throw new AgentDefinitionParseException(
                AgentDefinitionParseErrorKind::MalformedYaml,
                value: 'The document must be a YAML mapping.',
            );
        }

        if ($parsed === []) {
            return [];
        }

        if (array_is_list($parsed)) {
            throw new AgentDefinitionParseException(
                AgentDefinitionParseErrorKind::MalformedYaml,
                value: 'The document must be a YAML mapping.',
            );
        }

        return $parsed;
    }

    /**
     * Step 1: format_version, defaulting to the configured current version
     * when absent, else must be a member of the configured supported set.
     *
     * @param array<string, mixed> $document
     */
    private function resolveFormatVersion(array $document): string
    {
        if (!array_key_exists('format_version', $document)) {
            return (string) config('llm-client.agent_definitions.current_format_version');
        }

        $stated = $document['format_version'];
        $statedString = is_scalar($stated) ? (string) $stated : '';
        $supported = config('llm-client.agent_definitions.supported_format_versions', []);

        if (!in_array($statedString, $supported, true)) {
            throw new AgentDefinitionParseException(
                AgentDefinitionParseErrorKind::UnrecognizedFormatVersion,
                value: $statedString,
            );
        }

        return $statedString;
    }

    /**
     * Step 2: full structural scan for any top-level or nested key
     * (memory.*, safety.*, tools.* when the mapping form is used) not
     * defined by this schema. Key-name validity only — the memory value
     * shape check (enabled/disabled) is step 7's own concern.
     *
     * Every bad key found across all four scans — not only the first — is
     * appended to $problems; none of the loops below stop early.
     *
     * @param array<string, mixed> $document
     * @param list<AgentDefinitionParseException|AgentDefinitionResolutionException> $problems
     */
    private function scanForUnknownKeys(array $document, array &$problems): void
    {
        foreach (array_keys($document) as $key) {
            if (!is_string($key) || !in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $problems[] = new AgentDefinitionParseException(AgentDefinitionParseErrorKind::UnknownKey, key: (string) $key);
            }
        }

        if (isset($document['memory']) && is_array($document['memory'])) {
            $validMemoryKeys = array_map(static fn (MemoryKind $kind): string => $kind->value, MemoryKind::cases());

            foreach (array_keys($document['memory']) as $key) {
                if (!is_string($key) || !in_array($key, $validMemoryKeys, true)) {
                    $problems[] = new AgentDefinitionParseException(AgentDefinitionParseErrorKind::UnknownKey, key: 'memory.' . (string) $key);
                }
            }
        }

        if (isset($document['tools']) && is_array($document['tools']) && !array_is_list($document['tools'])) {
            foreach (array_keys($document['tools']) as $key) {
                if (!is_string($key) || !in_array($key, self::TOOLS_KEYS, true)) {
                    $problems[] = new AgentDefinitionParseException(AgentDefinitionParseErrorKind::UnknownKey, key: 'tools.' . (string) $key);
                }
            }
        }

        if (isset($document['safety']) && is_array($document['safety'])) {
            foreach (array_keys($document['safety']) as $key) {
                if (!is_string($key) || !in_array($key, self::SAFETY_KEYS, true)) {
                    $problems[] = new AgentDefinitionParseException(AgentDefinitionParseErrorKind::UnknownKey, key: 'safety.' . (string) $key);
                }
            }
        }
    }

    /**
     * Step 3: name, required and non-empty after trim().
     *
     * @param array<string, mixed> $document
     */
    private function resolveName(array $document): string
    {
        $name = $document['name'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            throw new AgentDefinitionParseException(AgentDefinitionParseErrorKind::MissingName);
        }

        return $name;
    }

    /**
     * Step 4: instructions, defaulting to "" and size-bounded via
     * ToolResultCondenser::estimateTokens() against the effective token
     * limit (instructions_max_tokens, falling back to
     * context_window.injected_section_reserve when unset — a live read,
     * never a hardcoded copy).
     *
     * @param array<string, mixed> $document
     */
    private function resolveInstructions(array $document): string
    {
        $instructions = $document['instructions'] ?? '';
        $instructions = is_string($instructions) ? $instructions : (string) $instructions;

        $estimated = ToolResultCondenser::estimateTokens($instructions);
        $limit = config('llm-client.agent_definitions.instructions_max_tokens')
            ?? config('llm-client.context_window.injected_section_reserve');

        if ($estimated > $limit) {
            throw new AgentDefinitionParseException(
                AgentDefinitionParseErrorKind::InstructionsTooLong,
                value: ['estimated' => $estimated, 'limit' => $limit],
            );
        }

        return $instructions;
    }

    /**
     * Step 5: model, defaulting to null; when stated, must name an existing
     * LanguageModel row.
     *
     * @param array<string, mixed> $document
     */
    private function resolveModel(array $document): ?string
    {
        $model = $document['model'] ?? null;

        if ($model === null) {
            return null;
        }

        $model = (string) $model;

        if (!LanguageModel::where('name', $model)->exists()) {
            throw new AgentDefinitionResolutionException(AgentDefinitionResolutionErrorKind::UnknownModel, $model);
        }

        return $model;
    }

    /**
     * Step 6: capabilities, defaulting to all 5 ReducibleTool cases when the
     * key is entirely omitted. An explicitly-stated empty list ([]) is
     * honored as zero capabilities — omission and explicit-empty are
     * deliberately not the same thing (research.md D7).
     *
     * Every unrecognized entry — not only the first — is appended to
     * $problems and omitted from the resolved list; the loop never stops
     * early.
     *
     * @param array<string, mixed> $document
     * @param list<AgentDefinitionParseException|AgentDefinitionResolutionException> $problems
     * @return list<ReducibleTool>
     */
    private function resolveCapabilities(array $document, array &$problems): array
    {
        if (!array_key_exists('capabilities', $document)) {
            return ReducibleTool::cases();
        }

        $raw = $document['capabilities'];
        $raw = is_array($raw) ? $raw : [$raw];

        $capabilities = [];
        foreach ($raw as $entry) {
            $entryString = (string) $entry;
            $tool = ReducibleTool::tryFrom($entryString);

            if ($tool === null) {
                $problems[] = new AgentDefinitionResolutionException(AgentDefinitionResolutionErrorKind::UnknownCapability, $entryString);
                continue;
            }

            $capabilities[] = $tool;
        }

        return $capabilities;
    }

    /**
     * Step 7: memory, defaulting to every MemoryKind case true. Each stated
     * key was already confirmed a valid MemoryKind by scanForUnknownKeys()
     * (step 2); each stated value must be the literal string "enabled" or
     * "disabled", else UnknownKey with the dotted key path and the
     * offending literal (research.md D10 — a recognized key given a value
     * this schema has no defined meaning for is the same failure mode one
     * level down as an unrecognized key name).
     *
     * Every offending value — not only the first — is appended to
     * $problems; the loop never stops early.
     *
     * @param array<string, mixed> $document
     * @param list<AgentDefinitionParseException|AgentDefinitionResolutionException> $problems
     * @return array<string, bool>
     */
    private function resolveMemory(array $document, array &$problems): array
    {
        $memory = $this->defaultMemory();

        if (!array_key_exists('memory', $document)) {
            return $memory;
        }

        $raw = $document['memory'];
        $raw = is_array($raw) ? $raw : [];

        foreach ($raw as $key => $value) {
            $kind = is_string($key) ? MemoryKind::tryFrom($key) : null;

            // Unreachable in practice: an unrecognized memory.* key name
            // was already rejected by scanForUnknownKeys() above.
            if ($kind === null) {
                continue;
            }

            if ($value === 'enabled') {
                $memory[$kind->value] = true;
            } elseif ($value === 'disabled') {
                $memory[$kind->value] = false;
            } else {
                $problems[] = new AgentDefinitionParseException(
                    AgentDefinitionParseErrorKind::UnknownKey,
                    key: 'memory.' . $kind->value,
                    value: $value,
                );
            }
        }

        return $memory;
    }

    /**
     * Step 8: tools.allow / tools.deny. Accepts either a flat list (sugar
     * for {allow: <list>, deny: []}) or a {allow, deny} mapping. allow
     * defaults to ["*"], deny defaults to []. Every pattern the author
     * actually wrote in either list is checked for emptiness against the
     * live catalog — the synthesized default ["*"] (only when tools/
     * tools.allow is omitted entirely) is exempt (research.md D8/D3/SC-002).
     *
     * Every pattern that resolves empty — not only the first, across both
     * allow and deny — is appended to $problems; neither loop stops early.
     *
     * @param array<string, mixed> $document
     * @param list<array{operationId: string, method: string}> $catalog
     * @param list<AgentDefinitionParseException|AgentDefinitionResolutionException> $problems
     * @return array{0: list<string>, 1: list<string>}
     */
    private function resolveTools(array $document, array $catalog, array &$problems): array
    {
        $toolsAllow = ['*'];
        $toolsDeny = [];
        $allowIsDefault = true;

        if (array_key_exists('tools', $document)) {
            $raw = $document['tools'];

            if (is_array($raw) && array_is_list($raw)) {
                // Flat-list sugar: tools: [a, b] === tools: {allow: [a, b], deny: []}
                $toolsAllow = array_values($raw);
                $allowIsDefault = false;
            } elseif (is_array($raw)) {
                if (array_key_exists('allow', $raw)) {
                    $toolsAllow = array_values((array) $raw['allow']);
                    $allowIsDefault = false;
                }

                if (array_key_exists('deny', $raw)) {
                    $toolsDeny = array_values((array) $raw['deny']);
                }
            }
        }

        if (!$allowIsDefault) {
            foreach ($toolsAllow as $pattern) {
                $this->collectPatternProblem((string) $pattern, $catalog, $problems);
            }
        }

        foreach ($toolsDeny as $pattern) {
            $this->collectPatternProblem((string) $pattern, $catalog, $problems);
        }

        return [array_map(static fn ($p): string => (string) $p, $toolsAllow), array_map(static fn ($p): string => (string) $p, $toolsDeny)];
    }

    /**
     * Steps 9-11: safety.confirmation_required / safety.denylist /
     * safety.unattended_authorized. Same shape, all three default []. Bare
     * HTTP-verb tokens are exempt from the emptiness check (a verb always
     * denotes "every operation with this method," checked only against the
     * fixed 5-verb set).
     *
     * Every pattern that resolves empty — not only the first — is
     * appended to $problems; the loop never stops early.
     *
     * @param array<string, mixed> $document
     * @param list<array{operationId: string, method: string}> $catalog
     * @param list<AgentDefinitionParseException|AgentDefinitionResolutionException> $problems
     * @return list<string>
     */
    private function resolveSafetyList(array $document, string $key, array $catalog, array &$problems): array
    {
        $safety = $document['safety'] ?? [];
        $safety = is_array($safety) ? $safety : [];

        $raw = $safety[$key] ?? [];
        $raw = is_array($raw) ? array_values($raw) : [];

        $resolved = [];
        foreach ($raw as $pattern) {
            $patternString = (string) $pattern;
            $resolved[] = $patternString;

            if (in_array($patternString, self::HTTP_VERBS, true)) {
                continue;
            }

            $this->collectPatternProblem($patternString, $catalog, $problems);
        }

        return $resolved;
    }

    /**
     * @param list<array{operationId: string, method: string}> $catalog
     * @param list<AgentDefinitionParseException|AgentDefinitionResolutionException> $problems
     */
    private function collectPatternProblem(string $pattern, array $catalog, array &$problems): void
    {
        if (OperationGroupPattern::resolve([$pattern], $catalog) === []) {
            $problems[] = new AgentDefinitionResolutionException(AgentDefinitionResolutionErrorKind::EmptyOperationPattern, $pattern);
        }
    }

    /**
     * Builds the live [{operationId, method}, ...] catalog author-written
     * patterns are checked against — ApiManager::getOperations() for the
     * full operationId set, plus one getOperationDetails() lookup per
     * candidate for its method. Resolved once per parse() call.
     *
     * @return list<array{operationId: string, method: string}>
     */
    private function resolveCatalog(): array
    {
        $catalog = [];

        foreach (ApiManager::getOperations() as $operation) {
            $details = (array) ApiManager::getOperationDetails($operation['operationId']);

            if (!isset($details['method'])) {
                continue;
            }

            $catalog[] = [
                'operationId' => $operation['operationId'],
                'method' => $details['method'],
            ];
        }

        return $catalog;
    }
}
