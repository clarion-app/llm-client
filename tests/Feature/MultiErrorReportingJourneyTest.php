<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionParseErrorKind;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionResolutionErrorKind;
use Dedoc\Scramble\Generator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AgentDefinitionParser::collect() (088-agent-definition-validator, spec.md
 * US1 Acceptance Scenarios 1-3): every problem a definition contains is
 * reported together, in one collect() call, each with its own distinct
 * kind/key-or-value/message -- never one generic failure, never stopping
 * at the first mistake found. Deliberately independent of any HTTP
 * endpoint (spec.md US1's own Independent Test framing: "Can be fully
 * tested by submitting a single definition containing several distinct,
 * unrelated mistakes at once, checking it...") -- User Story 2 is what
 * wires this onto POST /agents/check, out of this file's scope.
 */
class MultiErrorReportingJourneyTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function a_realistic_multi_mistake_document_reports_all_three_problems_together(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        // Three unrelated mistakes, from three different steps: a
        // misspelled setting ("memroy"), a model that is not available,
        // and an operation pattern that matches nothing.
        $raw = <<<YAML
name: my-agent
memroy:
  scratch: enabled
model: totally-not-a-real-model
tools:
  allow:
    - contakts.*
YAML;

        $result = (new AgentDefinitionParser())->collect($raw);

        $this->assertCount(3, $result->problems);

        $this->assertInstanceOf(AgentDefinitionParseException::class, $result->problems[0]);
        $this->assertSame(AgentDefinitionParseErrorKind::UnknownKey, $result->problems[0]->kind);
        $this->assertSame('memroy', $result->problems[0]->key);

        $this->assertInstanceOf(AgentDefinitionResolutionException::class, $result->problems[1]);
        $this->assertSame(AgentDefinitionResolutionErrorKind::UnknownModel, $result->problems[1]->kind);
        $this->assertSame('totally-not-a-real-model', $result->problems[1]->value);

        $this->assertInstanceOf(AgentDefinitionResolutionException::class, $result->problems[2]);
        $this->assertSame(AgentDefinitionResolutionErrorKind::EmptyOperationPattern, $result->problems[2]->kind);
        $this->assertSame('contakts.*', $result->problems[2]->value);

        // Each problem's own message names its own offending setting --
        // never one generic failure covering all three.
        $this->assertStringContainsString('memroy', $result->problems[0]->getMessage());
        $this->assertStringContainsString('totally-not-a-real-model', $result->problems[1]->getMessage());
        $this->assertStringContainsString('contakts.*', $result->problems[2]->getMessage());
    }

    #[Test]
    public function a_definition_with_no_problems_at_all_reports_no_problems(): void
    {
        $this->seedOperationCatalog([]);

        $raw = <<<YAML
name: clean-agent
YAML;

        $result = (new AgentDefinitionParser())->collect($raw);

        $this->assertSame([], $result->problems);
    }

    #[Test]
    public function the_same_mistake_named_twice_is_reported_as_two_distinct_entries(): void
    {
        $this->seedOperationCatalog([]);

        $raw = <<<YAML
name: my-agent
capabilities:
  - not_a_real_capability
  - also_not_real
YAML;

        $result = (new AgentDefinitionParser())->collect($raw);

        $this->assertCount(2, $result->problems);

        $this->assertSame(AgentDefinitionResolutionErrorKind::UnknownCapability, $result->problems[0]->kind);
        $this->assertSame('not_a_real_capability', $result->problems[0]->value);

        $this->assertSame(AgentDefinitionResolutionErrorKind::UnknownCapability, $result->problems[1]->kind);
        $this->assertSame('also_not_real', $result->problems[1]->value);

        $this->assertNotSame($result->problems[0], $result->problems[1]);
    }

    /**
     * Seeds both of ApiManager's live-catalog seams -- see
     * AgentDefinitionFullJourneyTest/AgentDefinitionSafetyCeilingJourneyTest
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
