<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionParseErrorKind;
use Dedoc\Scramble\Generator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AgentDefinitionParser::parse()'s format_version edge case
 * (086-agent-yaml-schema, spec.md Edge Cases section, FR-009/FR-010,
 * SC-005, quickstart.md step 10, mutation-checklist row 5): a recognized
 * format_version parses, an unrecognized one fails with an explicit
 * explanation naming both the stated value and the supported set, an
 * omitted format_version defaults to "1.0" and parses identically to
 * stating it explicitly, and the format_version key is never conflated
 * with the agent's own, independent version label (research.md D2).
 */
class AgentDefinitionFormatVersionJourneyTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function a_recognized_format_version_parses_successfully(): void
    {
        $this->seedOperationCatalog([]);

        $raw = <<<YAML
        format_version: "1.0"
        name: my-agent
        YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        $this->assertSame('1.0', $definition->formatVersion);
        $this->assertSame('my-agent', $definition->name);
    }

    #[Test]
    public function an_unrecognized_format_version_throws_naming_the_stated_value_and_the_supported_set(): void
    {
        $this->seedOperationCatalog([]);

        $raw = <<<YAML
        format_version: "9.9"
        name: my-agent
        YAML;

        try {
            (new AgentDefinitionParser())->parse($raw);
            $this->fail('Expected AgentDefinitionParseException for an unrecognized format_version.');
        } catch (AgentDefinitionParseException $e) {
            $this->assertSame(AgentDefinitionParseErrorKind::UnrecognizedFormatVersion, $e->kind);
            $this->assertSame('9.9', $e->value);
            $this->assertStringContainsString('9.9', $e->getMessage());
            $this->assertStringContainsString('1.0', $e->getMessage());
        }
    }

    #[Test]
    public function an_omitted_format_version_defaults_to_1_0_and_parses_identically_to_stating_it_explicitly(): void
    {
        $this->seedOperationCatalog([]);

        $parser = new AgentDefinitionParser();

        $explicit = $parser->parse(<<<YAML
        format_version: "1.0"
        name: my-agent
        YAML);

        $omitted = $parser->parse(<<<YAML
        name: my-agent
        YAML);

        $this->assertSame('1.0', $omitted->formatVersion);

        // Field-for-field equality between the two results, mirroring
        // AgentDefinitionMinimalJourneyTest's own determinism assertion.
        $this->assertSame($explicit->formatVersion, $omitted->formatVersion);
        $this->assertSame($explicit->name, $omitted->name);
        $this->assertSame($explicit->version, $omitted->version);
        $this->assertSame($explicit->instructions, $omitted->instructions);
        $this->assertSame($explicit->model, $omitted->model);
        $this->assertSame($explicit->memory, $omitted->memory);
        $this->assertSame($explicit->capabilities, $omitted->capabilities);
        $this->assertSame($explicit->toolsAllow, $omitted->toolsAllow);
        $this->assertSame($explicit->toolsDeny, $omitted->toolsDeny);
        $this->assertSame($explicit->safetyConfirmationRequired, $omitted->safetyConfirmationRequired);
        $this->assertSame($explicit->safetyDenylist, $omitted->safetyDenylist);
    }

    /**
     * Mutation-checklist row 5 — a document stating only the agent's own
     * `version` label, with no `format_version` key at all, must still
     * default `formatVersion` to "1.0" and parse successfully. The
     * mutation this guards against (collapsing the two-key split,
     * research.md D2, into one shared field) would instead misroute
     * "2.0.0" into the format-compatibility check and wrongly reject this
     * exact document as an unsupported format version.
     */
    #[Test]
    public function stating_only_the_agents_own_version_label_never_conflates_it_with_format_version(): void
    {
        $this->seedOperationCatalog([]);

        $raw = <<<YAML
        name: my-agent
        version: "2.0.0"
        YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        $this->assertSame('1.0', $definition->formatVersion);
        $this->assertSame('2.0.0', $definition->version);
    }

    /**
     * Seeds both of ApiManager's live-catalog seams — see
     * AgentDefinitionMinimalJourneyTest/AgentDefinitionUnknownNameJourneyTest
     * for the established convention this mirrors exactly.
     *
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
