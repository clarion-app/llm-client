<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentDefinitionValidator;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionWarningKind;
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

    // -----------------------------------------------------------------
    // Warning computation (088-agent-definition-validator, Phase 5/US3,
    // research.md D2). check()->warnings still stubs [] until T029 lands
    // the real computation -- every assertion below is expected to be
    // genuinely RED until then.
    // -----------------------------------------------------------------

    #[Test]
    public function a_delete_operation_permitted_via_tools_allow_with_no_covering_confirmation_produces_exactly_one_warning(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
        ]);

        $raw = <<<YAML
name: risky-agent
tools:
  allow:
    - contacts.destroy
YAML;

        $result = (new AgentDefinitionValidator(new AgentDefinitionParser()))->check($raw);

        $this->assertCount(1, $result->warnings);
        $this->assertSame(AgentDefinitionWarningKind::DestructiveOperationWithoutConfirmation, $result->warnings[0]->kind);
        $this->assertSame('contacts.destroy', $result->warnings[0]->operationId);
        $this->assertSame('DELETE', $result->warnings[0]->method);
    }

    #[Test]
    public function the_identical_document_with_a_covering_confirmation_required_entry_produces_no_warning(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
        ]);

        $raw = <<<YAML
name: risky-agent
tools:
  allow:
    - contacts.destroy
safety:
  confirmation_required:
    - contacts.destroy
YAML;

        $result = (new AgentDefinitionValidator(new AgentDefinitionParser()))->check($raw);

        $this->assertSame([], $result->warnings);
    }

    #[Test]
    public function the_identical_document_with_the_operation_also_denied_produces_no_warning(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
        ]);

        // Denied-and-thus-not-actually-permitted is not a warning
        // candidate at all (research.md D2's "permitted-and-not-denied"
        // condition) -- an author who both allows and denies the same
        // operation has, in effect, not granted it.
        $raw = <<<YAML
name: risky-agent
tools:
  allow:
    - contacts.destroy
  deny:
    - contacts.destroy
YAML;

        $result = (new AgentDefinitionValidator(new AgentDefinitionParser()))->check($raw);

        $this->assertSame([], $result->warnings);
    }

    #[Test]
    public function the_warning_reads_the_documents_own_confirmation_list_directly_never_via_the_ceiling_unioned_isConfirmationRequired(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
        ]);
        $this->app['config']->set('llm-client.confirm_methods', ['DELETE']);

        $raw = <<<YAML
name: ceiling-agent
tools:
  allow:
    - contacts.destroy
YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        // The installation's own confirm_methods ceiling already makes
        // isConfirmationRequired() return true for this operation,
        // regardless of the document's own (empty) safety.confirmation_
        // required list -- exactly the trap research.md D2 warns about.
        // If the warning computation read isConfirmationRequired()
        // instead of the document's own safetyConfirmationRequired list
        // directly, this fact alone would make the warning permanently
        // unreachable on this (the default) installation config.
        $this->assertTrue($definition->isConfirmationRequired('contacts.destroy'));

        $result = (new AgentDefinitionValidator(new AgentDefinitionParser()))->check($raw);

        $this->assertCount(1, $result->warnings);
        $this->assertSame(AgentDefinitionWarningKind::DestructiveOperationWithoutConfirmation, $result->warnings[0]->kind);
        $this->assertSame('contacts.destroy', $result->warnings[0]->operationId);
        $this->assertSame('DELETE', $result->warnings[0]->method);
    }

    #[Test]
    public function one_catalog_snapshot_covers_the_whole_check_call_including_the_warning_computation(): void
    {
        $operations = [
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
            'weather.get_forecast' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Get forecast'],
        ];

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

        // A hard call-count expectation: getOperations() must be called
        // exactly once for the *whole* check() call -- never once inside
        // collect()'s own pattern steps and again for the warning
        // computation (research.md D7).
        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->once()->andReturn($doc);
        $this->app->instance(Generator::class, $generator);

        $raw = <<<YAML
name: catalog-agent
tools:
  allow:
    - contacts.*
  deny:
    - weather.get_forecast
safety:
  confirmation_required:
    - contacts.store
  denylist:
    - weather.get_forecast
YAML;

        $result = (new AgentDefinitionValidator(new AgentDefinitionParser()))->check($raw);

        $this->assertSame([], $result->problems);
        $this->assertCount(1, $result->warnings);
        $this->assertSame('contacts.destroy', $result->warnings[0]->operationId);
    }

    #[Test]
    public function warnings_are_computed_regardless_of_whether_problems_is_empty(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
        ]);

        // Combines a blocking problem (an unrecognized capability) with
        // the destructive-without-confirmation warning shape -- US3 AC3:
        // computing the warning must never be short-circuited by an
        // already-present blocking problem.
        $raw = <<<YAML
name: risky-and-broken-agent
capabilities:
  - not_a_real_capability
tools:
  allow:
    - contacts.destroy
YAML;

        $result = (new AgentDefinitionValidator(new AgentDefinitionParser()))->check($raw);

        $this->assertFalse($result->valid);
        $this->assertCount(1, $result->problems);
        $this->assertCount(1, $result->warnings);
        $this->assertSame(AgentDefinitionWarningKind::DestructiveOperationWithoutConfirmation, $result->warnings[0]->kind);
        $this->assertSame('contacts.destroy', $result->warnings[0]->operationId);
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
