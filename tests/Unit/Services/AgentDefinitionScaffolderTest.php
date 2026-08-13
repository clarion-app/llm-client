<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentDefinitionScaffolder;
use ClarionApp\LlmClient\Services\AgentDefinitionValidator;
use ClarionApp\LlmClient\ValueObjects\AgentDefinition;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionParseErrorKind;
use ClarionApp\LlmClient\ValueObjects\AgentKind;
use ClarionApp\LlmClient\ValueObjects\GeneratedScaffold;
use Dedoc\Scramble\Generator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for AgentDefinitionScaffolder (089-agent-scaffolding-cli,
 * Phase 3/US1, contracts §3, data-model.md §3, research.md D2/D3/D10/D12).
 *
 * Written before AgentDefinitionScaffolder exists — every test in this
 * file is expected to fail with a "class not found"-style error until
 * Phase 3's own Implementation tasks (T015) create it. That is the
 * intended RED state, not a mistake.
 *
 * Every scenario that reaches AgentDefinitionParser::parse()/collect()
 * (directly or via AgentDefinitionScaffolder::generate()) seeds the live
 * operation catalog first (Grounding note 15) — even the empty-catalog
 * cases — using AgentDefinitionMinimalJourneyTest.php's own
 * seedOperationCatalog()/clearOperationCatalog() reflection + Generator-mock
 * pattern, copied verbatim.
 */
class AgentDefinitionScaffolderTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();
        parent::tearDown();
    }

    private function scaffolder(): AgentDefinitionScaffolder
    {
        $parser = new AgentDefinitionParser();

        return new AgentDefinitionScaffolder($parser, new AgentDefinitionValidator($parser));
    }

    private function validator(): AgentDefinitionValidator
    {
        return new AgentDefinitionValidator(new AgentDefinitionParser());
    }

    /**
     * Every one of the 15 leaf settings quickstart.md step 1 names
     * (format_version, name, version, instructions, model, memory.* x5,
     * capabilities, tools.allow, tools.deny, safety.confirmation_required,
     * safety.denylist), each immediately preceded (skipping only blank
     * lines) by a non-empty "#" comment line. A per-line scan, not a
     * fragile full-file string match (quickstart step 1's own instruction)
     * — tolerant of either a nested-YAML or a dotted-key rendering choice,
     * since only the leaf key name and its immediate ":" are required to
     * appear, preceded by a "." or whitespace character.
     */
    private function assertLeafSettingsHaveComments(string $content): void
    {
        $labels = [
            'format_version', 'name', 'version', 'instructions', 'model',
            'scratch', 'short_term', 'long_term', 'episodic', 'declarative',
            'capabilities', 'allow', 'deny', 'confirmation_required', 'denylist',
        ];

        $lines = explode("\n", $content);

        foreach ($labels as $label) {
            $keyLineIndex = null;

            foreach ($lines as $index => $line) {
                if (preg_match('/(^|[.\s])'.preg_quote($label, '/').'\s*:/', $line) === 1) {
                    $keyLineIndex = $index;
                    break;
                }
            }

            $this->assertNotNull($keyLineIndex, "Expected a \"{$label}\" setting line in the rendered content.");

            $commentLineIndex = $keyLineIndex - 1;
            while ($commentLineIndex >= 0 && trim($lines[$commentLineIndex]) === '') {
                $commentLineIndex--;
            }

            $this->assertGreaterThanOrEqual(0, $commentLineIndex, "Expected a comment line before \"{$label}\".");

            $commentLine = trim($lines[$commentLineIndex]);
            $this->assertStringStartsWith('#', $commentLine, "Expected \"{$label}\" to be preceded by a comment line.");
            $this->assertGreaterThan(1, strlen($commentLine), "Expected the comment before \"{$label}\" to be non-empty (not just a bare \"#\").");
        }
    }

    // ---------------------------------------------------------------
    // 1. Complete, valid content for a bare name (US1 AC1/AC2/AC3,
    //    FR-001-FR-004, quickstart step 1)
    // ---------------------------------------------------------------

    #[Test]
    public function generates_complete_valid_immediately_runnable_content_for_a_bare_name(): void
    {
        $this->seedOperationCatalog([]);

        $scaffold = $this->scaffolder()->generate('weather-agent', null);

        $this->assertInstanceOf(GeneratedScaffold::class, $scaffold);
        $this->assertSame('weather-agent', $scaffold->name);
        $this->assertNull($scaffold->kindSlug);
        $this->assertSame('weather-agent.yaml', $scaffold->filename);

        $this->assertLeafSettingsHaveComments($scaffold->content);

        $result = $this->validator()->check($scaffold->content);
        $this->assertTrue($result->valid);
        $this->assertSame([], $result->problems);
    }

    // ---------------------------------------------------------------
    // 2. The resolved name is the parser-trimmed value, never the raw
    //    argument (research.md D10, mutation-checklist row 8 precursor)
    // ---------------------------------------------------------------

    #[Test]
    public function the_resolved_name_is_the_parser_trimmed_value_never_the_raw_argument(): void
    {
        $this->seedOperationCatalog([]);

        $scaffold = $this->scaffolder()->generate('  padded-name  ', null);

        $this->assertSame('padded-name', $scaffold->name);
        $this->assertSame('padded-name.yaml', $scaffold->filename);
    }

    // ---------------------------------------------------------------
    // 3. A blank/whitespace-only name propagates MissingName uncaught
    //    (research.md D10)
    // ---------------------------------------------------------------

    #[Test]
    public function a_blank_name_propagates_missing_name_uncaught(): void
    {
        $this->seedOperationCatalog([]);

        try {
            $this->scaffolder()->generate('   ', null);
            $this->fail('Expected AgentDefinitionParseException to be thrown for a blank name.');
        } catch (AgentDefinitionParseException $e) {
            $this->assertSame(AgentDefinitionParseErrorKind::MissingName, $e->kind);
            $this->assertSame('A definition must state a non-empty "name".', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------
    // 4. A kind's overrides can never clobber the caller's name
    //    (research.md D2/D3, mutation-checklist row 7)
    // ---------------------------------------------------------------

    #[Test]
    public function a_kinds_overrides_can_never_clobber_the_callers_name(): void
    {
        $this->seedOperationCatalog([]);

        // Bypass AgentKind's private constructor via reflection — the only
        // way to build a malformed instance whose overrides contain a
        // "name" key, since research()/coding() never produce one.
        $reflection = new \ReflectionClass(AgentKind::class);
        $constructor = $reflection->getConstructor();
        $constructor->setAccessible(true);
        $malformedKind = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($malformedKind, 'malformed', 'A malformed kind used only to prove name cannot be clobbered.', [
            'name' => 'attacker-supplied',
        ]);

        $scaffold = $this->scaffolder()->generate('caller-name', $malformedKind);

        $this->assertSame('caller-name', $scaffold->name, 'A kind override must never be able to clobber the caller-supplied name.');
    }

    // ---------------------------------------------------------------
    // 5. The internal re-validation assertion is real, not a no-op
    //    (research.md D3/D12) — requires AgentDefinitionScaffolder::render()
    //    to be `protected`, a deliberate test seam.
    // ---------------------------------------------------------------

    #[Test]
    public function the_internal_revalidation_assertion_throws_a_logic_exception_when_the_renderer_produces_invalid_content(): void
    {
        $this->seedOperationCatalog([]);

        $parser = new AgentDefinitionParser();
        $scaffolder = new class($parser, new AgentDefinitionValidator($parser)) extends AgentDefinitionScaffolder {
            protected function render(AgentDefinition $definition): string
            {
                // Deliberately invalid: no "name" key at all, and an
                // unrecognized top-level key — AgentDefinitionValidator::check()
                // must report this invalid, proving generate() really does
                // feed its own rendered output back through the validator
                // rather than merely assuming it is correct.
                return "unrecognized_top_level_key: true\n";
            }
        };

        $this->expectException(\LogicException::class);

        $scaffolder->generate('weather-agent', null);
    }

    // ---------------------------------------------------------------
    // 6. FR-014 "stay in step" — rendered content reflects the live
    //    config, never a frozen copy (quickstart step 10)
    // ---------------------------------------------------------------

    #[Test]
    public function rendered_content_reflects_the_live_config_never_a_hardcoded_copy(): void
    {
        $this->seedOperationCatalog([]);

        $originalCurrent = config('llm-client.agent_definitions.current_format_version');
        $originalSupported = config('llm-client.agent_definitions.supported_format_versions');

        $overriddenSupported = array_values(array_unique(array_merge($originalSupported, ['2.5'])));
        config(['llm-client.agent_definitions.supported_format_versions' => $overriddenSupported]);
        config(['llm-client.agent_definitions.current_format_version' => '2.5']);

        try {
            $scaffold = $this->scaffolder()->generate('weather-agent', null);

            $this->assertMatchesRegularExpression(
                '/format_version:\s*[\'"]?2\.5[\'"]?/',
                $scaffold->content,
                'Expected the rendered format_version line to reflect the overridden live config, not a hardcoded default.'
            );
        } finally {
            config(['llm-client.agent_definitions.current_format_version' => $originalCurrent]);
            config(['llm-client.agent_definitions.supported_format_versions' => $originalSupported]);
        }
    }

    // ---------------------------------------------------------------
    // Operation catalog fixture (copied verbatim from
    // AgentDefinitionMinimalJourneyTest.php's own established pattern)
    // ---------------------------------------------------------------

    /**
     * @param array<string, array{path: string, method: string, summary: string}> $operations
     */
    private function seedOperationCatalog(array $operations): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
            ];
        }
        $doc = ['paths' => $paths];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}
