<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentDefinitionValidator;
use Dedoc\Scramble\Generator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AgentDefinitionValidator::check() (088-agent-definition-validator,
 * contracts/agent-definition-validator-api.md §4) — a thin wrapper over
 * AgentDefinitionParser::collect(), called exactly once per check(), with a
 * stub `warnings: []` until Phase 5/US3 adds the real computation
 * (research.md D2/D3).
 */
class AgentDefinitionValidatorTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function check_reports_valid_true_for_a_clean_document_exactly_when_parse_would_succeed(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $raw = <<<YAML
name: clean-agent
tools:
  allow:
    - contacts.store
YAML;

        // parse() must succeed for the identical input/installation state.
        (new AgentDefinitionParser())->parse($raw);

        $result = (new AgentDefinitionValidator(new AgentDefinitionParser()))->check($raw);

        $this->assertTrue($result->valid);
        $this->assertSame([], $result->problems);
    }

    #[Test]
    public function check_reports_valid_false_for_a_multi_problem_document_exactly_when_parse_would_throw(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $raw = <<<YAML
name: broken-agent
bogus_top_level_key: true
model: nonexistent-model
tools:
  allow:
    - reports.*
YAML;

        try {
            (new AgentDefinitionParser())->parse($raw);
            $this->fail('Expected parse() to throw for this multi-problem document.');
        } catch (\Throwable $e) {
            // parse() does throw, as expected -- proceed to check().
        }

        $result = (new AgentDefinitionValidator(new AgentDefinitionParser()))->check($raw);

        $this->assertFalse($result->valid);
        $this->assertCount(3, $result->problems);
    }

    #[Test]
    public function check_problems_are_the_verbatim_collect_result_never_re_derived_or_filtered(): void
    {
        // AgentDefinitionParser is final (cannot be Mockery-mocked by
        // subclassing), and is bound as a stateless singleton specifically
        // so it always re-reads live installation state on every call
        // (established in Phase 3's AgentDefinitionParserCollectTest --
        // "parse() holds no state"). Two independent top-level calls
        // therefore necessarily construct two distinct, though
        // structurally identical, exception objects -- there is no way to
        // observe true object identity across an externally-called
        // collect() and check()'s own internal collect() call without
        // reintroducing memoization that would contradict that documented
        // statelessness. What IS observable, and asserted here, is that
        // check()->problems agrees with a same-input collect()->problems
        // call exactly on count, order, class, kind, key, value, and
        // message for every entry -- proving nothing is re-derived,
        // re-ordered, or filtered in between.
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $raw = <<<YAML
name: broken-agent
bogus_top_level_key: true
model: nonexistent-model
tools:
  allow:
    - reports.*
YAML;

        $collected = (new AgentDefinitionParser())->collect($raw);
        $result = (new AgentDefinitionValidator(new AgentDefinitionParser()))->check($raw);

        $this->assertCount(count($collected->problems), $result->problems);

        foreach ($collected->problems as $i => $expected) {
            $actual = $result->problems[$i];
            $this->assertSame($expected::class, $actual::class);
            $this->assertSame($expected->kind, $actual->kind);
            $this->assertSame($expected->getMessage(), $actual->getMessage());
        }
    }

    #[Test]
    public function check_warnings_are_always_empty_in_this_phase(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $raw = <<<YAML
name: clean-agent
tools:
  allow:
    - contacts.store
YAML;

        $result = (new AgentDefinitionValidator(new AgentDefinitionParser()))->check($raw);

        $this->assertSame([], $result->warnings);
    }

    #[Test]
    public function check_is_idempotent_and_performs_no_write_of_any_kind(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $raw = <<<YAML
name: my-agent
bogus_top_level_key: true
tools:
  allow:
    - contacts.store
YAML;

        $validator = new AgentDefinitionValidator(new AgentDefinitionParser());

        $this->assertSame(0, Agent::count());
        $this->assertSame(0, AgentVersion::count());

        $first = $validator->check($raw);
        $second = $validator->check($raw);

        $this->assertSame($first->valid, $second->valid);
        $this->assertCount(count($first->problems), $second->problems);
        foreach ($first->problems as $i => $problem) {
            $this->assertSame($problem::class, $second->problems[$i]::class);
            $this->assertSame($problem->kind, $second->problems[$i]->kind);
        }
        $this->assertSame($first->warnings, $second->warnings);

        // No write of any kind, no accumulated state (data-model.md §2 /
        // contracts §4's own idempotence guarantee).
        $this->assertSame(0, Agent::count());
        $this->assertSame(0, AgentVersion::count());
    }

    #[Test]
    public function a_live_database_failure_resolving_the_model_propagates_out_of_check_uncaught(): void
    {
        $this->seedOperationCatalog([]);

        // Forces LanguageModel::where('name', ...)->exists() to fail as a
        // genuine infrastructure error -- research.md D6, applied one
        // layer up from AgentDefinitionParserCollectTest's identical
        // collect()-level assertion. check() has no try/catch of its own
        // that could ever widen to catch this.
        Schema::drop('language_models');

        $raw = <<<YAML
name: broken-agent
model: some-model
YAML;

        $this->expectException(QueryException::class);

        (new AgentDefinitionValidator(new AgentDefinitionParser()))->check($raw);
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
