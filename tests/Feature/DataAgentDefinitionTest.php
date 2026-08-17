<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\ValueObjects\AgentDefinition;
use Dedoc\Scramble\Generator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A data question answered by the data agent must always be traceable: the
 * reply has to say which data source(s) it actually queried and what time
 * period the reported figures cover, defaulting and stating a period when
 * the question itself did not name one, and it must never claim to have
 * consulted a source or period it did not actually query.
 *
 * This guarantee rests on the template's own instructions text rather than
 * on any code path, so these assertions read the parsed instructions
 * directly, mirroring the same "the template requires X" pattern already
 * established for the other agent templates in this package.
 */
class DataAgentDefinitionTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function definition(): AgentDefinition
    {
        $this->seedCatalog();

        return (new AgentDefinitionParser())->parse(
            (string) file_get_contents(__DIR__ . '/../../src/Templates/data.yaml'),
        );
    }

    private function seedCatalog(): void
    {
        $doc = ['paths' => [
            '/api/contacts' => ['get' => ['operationId' => 'contacts.index', 'summary' => 'List contacts']],
        ]];

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

    // ---------------------------------------------------------------
    // Naming sources and the period covered
    // ---------------------------------------------------------------

    #[Test]
    public function instructions_require_naming_the_sources_queried_and_the_period_covered(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'Every answer states which data source(s) you actually queried for it,',
            $instructions,
            'every answer must be required to name the source(s) it actually queried',
        );
        $this->assertStringContainsString(
            'the time period the reported figures cover.',
            $instructions,
            'every answer must be required to state the time period its figures cover',
        );
    }

    #[Test]
    public function instructions_require_stating_a_default_period_when_the_question_did_not_specify_one(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'If a question does not specify a period, choose a reasonable default and',
            $instructions,
            'an unspecified period must be required to fall back to a stated, reasonable default',
        );
        $this->assertStringContainsString(
            'state which one you chose',
            $instructions,
            'a defaulted period must be required to be stated explicitly, never left implicit',
        );
        $this->assertStringContainsString(
            'never leave the period unstated.',
            $instructions,
            'leaving the period unstated must be explicitly forbidden',
        );
    }

    #[Test]
    public function instructions_forbid_citing_a_source_or_period_not_actually_queried(): void
    {
        $instructions = $this->definition()->instructions;

        $this->assertStringContainsString(
            'Only name a source or period you actually queried for that specific',
            $instructions,
            'a stated source or period must be required to reflect what was actually queried for that answer',
        );
        $this->assertStringContainsString(
            'Never cite a source or period you did not query.',
            $instructions,
            'citing a source or period that was not actually queried must be explicitly forbidden',
        );
    }
}
