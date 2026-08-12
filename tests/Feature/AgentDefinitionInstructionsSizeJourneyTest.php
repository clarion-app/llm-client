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
 * AgentDefinitionParser::parse()'s instructions size bound
 * (086-agent-yaml-schema, plan.md brief, research.md D4, quickstart.md
 * step 12, mutation-checklist row 8): instructions estimating (via
 * ToolResultCondenser::estimateTokens()) over the effective token bound
 * fail with a complaint naming both the estimated and allowed counts;
 * one character under the bound parses successfully; the effective bound
 * is instructions_max_tokens when set, else a LIVE read of
 * context_window.injected_section_reserve — never a hardcoded copy.
 */
class AgentDefinitionInstructionsSizeJourneyTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function instructions_estimating_over_the_effective_bound_throws_naming_estimated_and_allowed_counts(): void
    {
        $this->seedOperationCatalog([]);
        $this->app['config']->set('llm-client.agent_definitions.instructions_max_tokens', 10);

        // ToolResultCondenser::estimateTokens() is ceil(strlen/4); 41 chars
        // -> ceil(41/4) = 11 estimated tokens, exceeding a limit of 10.
        $instructions = str_repeat('x', 41);

        $raw = "name: my-agent\ninstructions: \"{$instructions}\"\n";

        try {
            (new AgentDefinitionParser())->parse($raw);
            $this->fail('Expected AgentDefinitionParseException for instructions exceeding the effective token bound.');
        } catch (AgentDefinitionParseException $e) {
            $this->assertSame(AgentDefinitionParseErrorKind::InstructionsTooLong, $e->kind);
            $this->assertIsArray($e->value);
            $this->assertSame(11, $e->value['estimated']);
            $this->assertSame(10, $e->value['limit']);
            $this->assertStringContainsString('11', $e->getMessage());
            $this->assertStringContainsString('10', $e->getMessage());
        }
    }

    #[Test]
    public function trimming_instructions_by_one_character_under_the_bound_parses_successfully(): void
    {
        $this->seedOperationCatalog([]);
        $this->app['config']->set('llm-client.agent_definitions.instructions_max_tokens', 10);

        // 40 chars -> ceil(40/4) = 10 estimated tokens, exactly at (not
        // over) a limit of 10 -- the boundary check is strictly ">".
        $instructions = str_repeat('x', 40);

        $raw = "name: my-agent\ninstructions: \"{$instructions}\"\n";

        $definition = (new AgentDefinitionParser())->parse($raw);

        $this->assertSame($instructions, $definition->instructions);
    }

    /**
     * Mutation-checklist row 8 — with agent_definitions.instructions_max_tokens
     * left null (unset), the effective bound must be a LIVE read of
     * context_window.injected_section_reserve, not a value copied at some
     * earlier point. Shrinking that config value must shrink the effective
     * bound in the same test run.
     */
    #[Test]
    public function the_fallback_bound_is_a_live_read_of_the_context_window_injected_section_reserve_config(): void
    {
        $this->seedOperationCatalog([]);
        $this->app['config']->set('llm-client.agent_definitions.instructions_max_tokens', null);
        $this->app['config']->set('llm-client.context_window.injected_section_reserve', 5);

        // 21 chars -> ceil(21/4) = 6 estimated tokens, exceeding the
        // shrunk fallback bound of 5 -- but comfortably under the
        // package's own real default of 1500, so this can only throw if
        // the fallback config value was actually re-read live.
        $instructions = str_repeat('x', 21);

        $raw = "name: my-agent\ninstructions: \"{$instructions}\"\n";

        try {
            (new AgentDefinitionParser())->parse($raw);
            $this->fail('Expected AgentDefinitionParseException: the fallback bound must move with context_window.injected_section_reserve.');
        } catch (AgentDefinitionParseException $e) {
            $this->assertSame(AgentDefinitionParseErrorKind::InstructionsTooLong, $e->kind);
            $this->assertSame(6, $e->value['estimated']);
            $this->assertSame(5, $e->value['limit']);
        }
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
