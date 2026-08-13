<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\AgentDefinition;
use ClarionApp\LlmClient\ValueObjects\AgentKind;
use ClarionApp\LlmClient\ValueObjects\GeneratedScaffold;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds a complete, immediately-runnable agent definition from a bare name
 * and an optional ready-made AgentKind (089-agent-scaffolding-cli, contracts
 * §3, data-model.md §3, research.md D2/D3/D10/D12).
 *
 * generate() builds the minimal document a caller could have hand-written,
 * resolves it through the real, unmodified AgentDefinitionParser::parse()
 * (so every default this format defines is applied exactly the way it would
 * be for any other document), renders the *resolved* AgentDefinition back
 * out as fully-commented YAML text, and re-validates that rendered text via
 * AgentDefinitionValidator::check() before ever returning it — a
 * belt-and-suspenders internal assertion, not blind trust in render()'s own
 * correctness (research.md D3/D12).
 */
class AgentDefinitionScaffolder
{
    public function __construct(
        private readonly AgentDefinitionParser $parser,
        private readonly AgentDefinitionValidator $validator,
    ) {
    }

    /**
     * `name` is merged into the document *last*, after any kind overrides,
     * so a kind's overrides can never clobber the caller-supplied name —
     * even a malformed kind whose overrides contain a "name" key
     * (research.md D2/D3).
     *
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException
     * @throws \LogicException Only on a scaffolding-rendering bug — never a content problem.
     */
    public function generate(string $name, ?AgentKind $kind = null): GeneratedScaffold
    {
        $document = [...($kind?->getOverrides() ?? []), 'name' => $name];

        $rawYaml = Yaml::dump($document, 4, 2);

        $definition = $this->parser->parse($rawYaml);

        $content = $this->render($definition);

        $result = $this->validator->check($content);

        if (!$result->valid) {
            throw new \LogicException(
                'AgentDefinitionScaffolder produced content that failed its own internal validation — this is a scaffolding bug, not a content problem.'
            );
        }

        $trimmedName = trim($definition->name);

        return new GeneratedScaffold(
            name: $trimmedName,
            kindSlug: $kind?->getSlug(),
            filename: Str::slug($trimmedName) . '.yaml',
            content: $content,
        );
    }

    /**
     * Renders the resolved AgentDefinition's own field values back out as
     * fully-commented YAML text, walking every field in a fixed order.
     * Every value is read live off $definition — never a hardcoded default
     * — so a live config change (e.g. the current format_version) is always
     * reflected (research.md D3/FR-014).
     *
     * Deliberately `protected`, not `private`: a test seam allowing a local
     * anonymous subclass to override this method and prove generate()'s own
     * internal re-validation assertion is real, not a no-op.
     */
    protected function render(AgentDefinition $definition): string
    {
        $lines = [];

        $lines[] = '# The schema version this document was written against.';
        $lines[] = $this->renderScalarLine('format_version', $definition->formatVersion);
        $lines[] = '';

        $lines[] = '# The agent\'s name.';
        $lines[] = $this->renderScalarLine('name', trim($definition->name));
        $lines[] = '';

        $lines[] = '# An optional free-form version label for this definition.';
        $lines[] = $this->renderScalarLine('version', $definition->version);
        $lines[] = '';

        $lines[] = '# The system instructions given to the agent.';
        $lines[] = $this->renderScalarLine('instructions', $definition->instructions);
        $lines[] = '';

        $lines[] = '# The language model this agent runs on. Omit to use the installation default.';
        $lines[] = $this->renderScalarLine('model', $definition->model);
        $lines[] = '';

        $lines[] = 'memory:';
        $lines[] = '  # Scratch memory: ephemeral notes kept only for the current turn.';
        $lines[] = $this->renderMemoryLine('scratch', $definition);
        $lines[] = '  # Short-term memory: recent context kept for the current conversation.';
        $lines[] = $this->renderMemoryLine('short_term', $definition);
        $lines[] = '  # Long-term memory: durable notes carried across conversations.';
        $lines[] = $this->renderMemoryLine('long_term', $definition);
        $lines[] = '  # Episodic memory: a record of past interactions and outcomes.';
        $lines[] = $this->renderMemoryLine('episodic', $definition);
        $lines[] = '  # Declarative memory: durable facts proposed and confirmed over time.';
        $lines[] = $this->renderMemoryLine('declarative', $definition);
        $lines[] = '';

        $lines[] = '# The memory-management tools this agent is allowed to call.';
        $lines[] = $this->renderListLine('capabilities', array_map(
            static fn ($tool) => $tool->value,
            $definition->capabilities,
        ));
        $lines[] = '';

        $lines[] = 'tools:';
        // Deliberately rendered empty rather than the parser's own
        // synthesized "all operations" default: an explicit, author-written
        // tools.allow pattern is checked against this installation's live
        // operation catalog (AgentDefinitionParser::resolveTools()), and a
        // scaffold must validate on every installation, including one whose
        // catalog happens to be empty at generation time. An empty list is
        // both always valid and the most conservative possible starting
        // point (FR-002) — the human adds operation patterns here to grant
        // access.
        $lines[] = '  # API operation patterns this agent is allowed to call. Starts empty (no API access) — add patterns here to grant access.';
        $lines[] = $this->renderIndentedListLine('allow', []);
        $lines[] = '  # API operation patterns this agent is never allowed to call.';
        $lines[] = $this->renderIndentedListLine('deny', $definition->toolsDeny);
        $lines[] = '';

        $lines[] = 'safety:';
        $lines[] = '  # Operations (patterns or bare HTTP verbs) requiring explicit human confirmation before this agent may call them.';
        $lines[] = $this->renderIndentedListLine('confirmation_required', $definition->safetyConfirmationRequired);
        $lines[] = '  # Operations (patterns or bare HTTP verbs) this agent is never allowed to call, regardless of tools.allow.';
        $lines[] = $this->renderIndentedListLine('denylist', $definition->safetyDenylist);

        return implode("\n", $lines) . "\n";
    }

    private function renderScalarLine(string $key, mixed $value): string
    {
        $dumped = trim(Yaml::dump([$key => $value]));

        return $dumped;
    }

    private function renderMemoryLine(string $key, AgentDefinition $definition): string
    {
        $value = $definition->memory[$key] ?? false ? 'enabled' : 'disabled';

        return '  ' . $key . ': ' . $value;
    }

    /**
     * @param list<string> $values
     */
    private function renderListLine(string $key, array $values): string
    {
        // Yaml::dump() cannot distinguish an empty list from an empty map
        // (PHP has one array type for both) and defaults to rendering an
        // empty array as "{  }" — a map, not the list this field is
        // documented to hold. Render the empty case explicitly as "[]" so
        // a human editing the file sees the list syntax they need to use
        // to add an entry, rather than mapping syntax that doesn't apply.
        if ($values === []) {
            return $key . ': []';
        }

        $dumped = trim(Yaml::dump([$key => $values], 2, 2));

        return $dumped;
    }

    /**
     * @param list<string> $values
     */
    private function renderIndentedListLine(string $key, array $values): string
    {
        if ($values === []) {
            return '  ' . $key . ': []';
        }

        $dumped = trim(Yaml::dump([$key => $values], 2, 2));

        $indented = implode("\n", array_map(
            static fn (string $line): string => '  ' . $line,
            explode("\n", $dumped),
        ));

        return $indented;
    }
}
