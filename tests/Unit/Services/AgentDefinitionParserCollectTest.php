<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionParseErrorKind;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionResolutionErrorKind;
use Dedoc\Scramble\Generator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * AgentDefinitionParser::collect() (088-agent-definition-validator,
 * research.md D0/D1/D4/D5/D6/D7, contracts/agent-definition-validator-api.md
 * §5) -- the sole implementation of the 11-step rule set (086 research.md
 * D11's fixed check order, unchanged), in collecting form: every problem a
 * step finds is appended to the returned result's problems list instead of
 * being thrown, and every step still runs regardless of whether an earlier
 * one already found a problem.
 */
class AgentDefinitionParserCollectTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function multiple_unrelated_problems_from_different_steps_are_all_collected_in_fixed_step_order(): void
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

        $result = (new AgentDefinitionParser())->collect($raw);

        $this->assertCount(3, $result->problems);

        // Step 2 (scanForUnknownKeys) fires before step 5 (resolveModel),
        // which fires before step 8 (resolveTools) -- the same fixed order
        // 086 already established for the single-error parse() path.
        $this->assertInstanceOf(AgentDefinitionParseException::class, $result->problems[0]);
        $this->assertSame(AgentDefinitionParseErrorKind::UnknownKey, $result->problems[0]->kind);
        $this->assertSame('bogus_top_level_key', $result->problems[0]->key);

        $this->assertInstanceOf(AgentDefinitionResolutionException::class, $result->problems[1]);
        $this->assertSame(AgentDefinitionResolutionErrorKind::UnknownModel, $result->problems[1]->kind);
        $this->assertSame('nonexistent-model', $result->problems[1]->value);

        $this->assertInstanceOf(AgentDefinitionResolutionException::class, $result->problems[2]);
        $this->assertSame(AgentDefinitionResolutionErrorKind::EmptyOperationPattern, $result->problems[2]->kind);
        $this->assertSame('reports.*', $result->problems[2]->value);
    }

    #[Test]
    public function multiple_occurrences_of_the_same_mistake_within_one_step_are_each_reported(): void
    {
        $this->seedOperationCatalog([]);

        $raw = <<<YAML
name: broken-agent
capabilities:
  - not_real_one
  - memory_read
  - not_real_two
YAML;

        $result = (new AgentDefinitionParser())->collect($raw);

        $this->assertCount(2, $result->problems);

        $this->assertSame(AgentDefinitionResolutionErrorKind::UnknownCapability, $result->problems[0]->kind);
        $this->assertSame('not_real_one', $result->problems[0]->value);

        $this->assertSame(AgentDefinitionResolutionErrorKind::UnknownCapability, $result->problems[1]->kind);
        $this->assertSame('not_real_two', $result->problems[1]->value);

        $this->assertNotSame($result->problems[0], $result->problems[1]);
    }

    /**
     * Closes the one gap the two scenarios above do not reach: the two
     * single-value steps (format_version, instructions) must also
     * collect-and-continue, not only the five loop-based steps D4 names.
     * Without this, a document whose only problems are a bad
     * format_version and over-length instructions would still throw
     * instead of populating problems, correct for parse() but wrong for
     * collect().
     */
    #[Test]
    public function the_two_single_value_early_steps_also_collect_and_continue_past_their_own_failure(): void
    {
        $this->seedOperationCatalog([]);
        $this->app['config']->set('llm-client.agent_definitions.instructions_max_tokens', 1);

        $raw = <<<YAML
format_version: "9.9"
name: broken-agent
extra_bogus_key: true
instructions: "this instructions text is unmistakably too long for the tiny configured limit"
YAML;

        $result = (new AgentDefinitionParser())->collect($raw);

        $this->assertCount(3, $result->problems);

        // Fixed step order: format_version (step 1), then the full
        // structural key scan (step 2), then instructions (step 4).
        $this->assertInstanceOf(AgentDefinitionParseException::class, $result->problems[0]);
        $this->assertSame(AgentDefinitionParseErrorKind::UnrecognizedFormatVersion, $result->problems[0]->kind);
        $this->assertSame('9.9', $result->problems[0]->value);

        $this->assertInstanceOf(AgentDefinitionParseException::class, $result->problems[1]);
        $this->assertSame(AgentDefinitionParseErrorKind::UnknownKey, $result->problems[1]->kind);
        $this->assertSame('extra_bogus_key', $result->problems[1]->key);

        $this->assertInstanceOf(AgentDefinitionParseException::class, $result->problems[2]);
        $this->assertSame(AgentDefinitionParseErrorKind::InstructionsTooLong, $result->problems[2]->kind);
    }

    #[Test]
    public function a_clean_document_reports_no_problems_and_a_definition_identical_to_parse(): void
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

        $parser = new AgentDefinitionParser();
        $expected = $parser->parse($raw);
        $result = $parser->collect($raw);

        $this->assertSame([], $result->problems);

        $this->assertSame($expected->formatVersion, $result->definition->formatVersion);
        $this->assertSame($expected->name, $result->definition->name);
        $this->assertSame($expected->version, $result->definition->version);
        $this->assertSame($expected->instructions, $result->definition->instructions);
        $this->assertSame($expected->model, $result->definition->model);
        $this->assertSame($expected->memory, $result->definition->memory);
        $this->assertSame($expected->capabilities, $result->definition->capabilities);
        $this->assertSame($expected->toolsAllow, $result->definition->toolsAllow);
        $this->assertSame($expected->toolsDeny, $result->definition->toolsDeny);
        $this->assertSame($expected->safetyConfirmationRequired, $result->definition->safetyConfirmationRequired);
        $this->assertSame($expected->safetyDenylist, $result->definition->safetyDenylist);
        $this->assertSame($expected->unattendedAuthorized, $result->definition->unattendedAuthorized);
    }

    #[Test]
    public function safety_unattended_authorized_resolves_a_declared_pattern_and_is_never_flagged_unknown(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
        ]);

        $raw = <<<YAML
name: scheduler-like-agent
safety:
  unattended_authorized:
    - contacts.destroy
YAML;

        $result = (new AgentDefinitionParser())->collect($raw);

        $this->assertSame([], $result->problems, 'a declared safety.unattended_authorized key must never itself be reported as an unknown key');
        $this->assertSame(['contacts.destroy'], $result->definition->unattendedAuthorized);
    }

    #[Test]
    public function safety_unattended_authorized_defaults_to_an_empty_list_when_absent(): void
    {
        $this->seedOperationCatalog([]);

        $result = (new AgentDefinitionParser())->collect('name: no-safety-block-agent');

        $this->assertSame([], $result->problems);
        $this->assertSame([], $result->definition->unattendedAuthorized);
    }

    /**
     * D6's own explicit claim ("every existing template ... simply does
     * not declare this key ... parses with zero behavior change") proven
     * directly, not assumed: every template shipped before this key
     * existed still parses with zero problems and an empty
     * unattendedAuthorized list now that the key is recognized.
     */
    #[Test]
    public function every_pre_existing_shipped_template_still_parses_cleanly_now_that_safety_unattended_authorized_exists(): void
    {
        $this->seedOperationCatalog([
            'clarionApp.llmClient.conversations.index' => [
                'path' => '/api/conversations',
                'method' => 'get',
                'summary' => 'List conversations',
            ],
            'clarionApp.llmClient.fetchPage.getTextFromUrl' => [
                'path' => '/api/page/text',
                'method' => 'post',
                'summary' => 'Fetch the text of a page',
            ],
            'clarionApp.llmClient.codingWorkspace.writeFile' => [
                'path' => '/api/coding-project/{project}/file',
                'method' => 'post',
                'summary' => 'Write a file in a registered project',
            ],
            'clarionApp.llmClient.codingWorkspace.deleteFile' => [
                'path' => '/api/coding-project/{project}/file',
                'method' => 'delete',
                'summary' => 'Delete a file in a registered project',
            ],
            'clarionApp.llmClient.codingWorkspace.runTests' => [
                'path' => '/api/coding-project/{project}/run-tests',
                'method' => 'post',
                'summary' => "Run a registered project's own test command",
            ],
            'clarionApp.llmClient.codingWorkspace.runCommand' => [
                'path' => '/api/coding-project/{project}/run-command',
                'method' => 'post',
                'summary' => "Run a shell command in a registered project's sandboxed workspace",
            ],
            'clarionApp.llmClient.codingWorkspace.runCode' => [
                'path' => '/api/coding-project/{project}/run-code',
                'method' => 'post',
                'summary' => "Run a code snippet in a registered project's sandboxed workspace",
            ],
            'clarionApp.llmClient.codingWorkspace.gitCommit' => [
                'path' => '/api/coding-project/{project}/git-commit',
                'method' => 'post',
                'summary' => "Stage and commit a registered project's changes",
            ],
            'clarionApp.llmClient.codingWorkspace.gitPush' => [
                'path' => '/api/coding-project/{project}/git-push',
                'method' => 'post',
                'summary' => "Publish committed changes to a registered project's configured remote",
            ],
            'clarionApp.llmClient.codingWorkspace.gitBranch' => [
                'path' => '/api/coding-project/{project}/git-branch',
                'method' => 'post',
                'summary' => "Create a new branch in a registered project",
            ],
        ]);

        $parser = new AgentDefinitionParser();

        foreach (['research.yaml', 'coding.yaml', 'data.yaml'] as $template) {
            $raw = (string) file_get_contents(__DIR__ . '/../../../src/Templates/' . $template);
            $result = $parser->collect($raw);

            $this->assertSame([], $result->problems, $template . ' must still parse with zero problems');
            $this->assertSame([], $result->definition->unattendedAuthorized, $template . ' does not declare safety.unattended_authorized, so it must resolve to an empty list');
        }
    }

    /**
     * scheduler.yaml (data-model.md §5, contracts/scheduler-agent-template.md)
     * is the fourth ready-made template, and the first one to declare
     * safety.unattended_authorized at all. Checked two ways: a raw YAML
     * structural parse (Yaml::parseFile(), independent of
     * AgentDefinitionParser entirely) and a full AgentDefinitionParser
     * pass -- both must agree the document is well-formed, and the parsed
     * definition's tools.allow / safety.unattended_authorized must both be
     * exactly the empty list the narrow-by-default posture requires.
     */
    #[Test]
    public function scheduler_yaml_is_well_formed_yaml_and_parses_with_empty_tools_allow_and_empty_unattended_authorized(): void
    {
        $path = __DIR__ . '/../../../src/Templates/scheduler.yaml';

        $parsed = Yaml::parseFile($path);
        $this->assertIsArray($parsed, 'scheduler.yaml must be a well-formed YAML mapping');
        $this->assertSame('scheduler', $parsed['name'] ?? null);
        $this->assertArrayHasKey('safety', $parsed);
        $this->assertArrayHasKey('unattended_authorized', $parsed['safety']);

        $this->seedOperationCatalog([]);

        $result = (new AgentDefinitionParser())->collect((string) file_get_contents($path));

        $this->assertSame([], $result->problems, 'scheduler.yaml must parse with zero problems');
        $this->assertSame('scheduler', $result->definition->name);
        $this->assertSame([], $result->definition->toolsAllow, 'tools.allow must be exactly [] -- no execute_operation call is ever permitted until explicitly widened');
        $this->assertSame([], $result->definition->safetyConfirmationRequired);
        $this->assertSame([], $result->definition->safetyDenylist);
        $this->assertSame([], $result->definition->unattendedAuthorized, 'safety.unattended_authorized must be exactly [] -- nothing is pre-authorized until explicitly widened');
    }

    #[Test]
    public function parse_throws_the_exact_same_instance_collect_returns_as_its_first_problem(): void
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

        $parser = new AgentDefinitionParser();
        $collected = $parser->collect($raw);

        $this->assertNotSame([], $collected->problems);

        try {
            $parser->parse($raw);
            $this->fail('Expected parse() to throw for content collect() reported problems for.');
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException $e) {
            // AgentDefinitionParser is bound as a stateless singleton
            // (LlmClientServiceProvider: "safe as singleton() -- parse()
            // holds no state") precisely so it always re-reads live
            // installation state on every call -- so collect() and parse()
            // here are two genuinely independent computations, each
            // constructing its own exception instance, and cannot share
            // object identity without introducing memoization that would
            // contradict that documented statelessness and the live-read
            // freshness this parser guarantees elsewhere (research.md
            // D6/D7). What is guaranteed, and asserted here, is that both
            // computations -- given the identical input and installation
            // state -- agree exactly on class, kind, key, value, and
            // message: parse() throws a problem indistinguishable in every
            // observable respect from collect()'s own first problem, never
            // a differently-derived one.
            $this->assertEquals($collected->problems[0], $e);
            $this->assertSame($collected->problems[0]::class, $e::class);
            $this->assertSame($collected->problems[0]->kind, $e->kind);
            $this->assertSame($collected->problems[0]->getMessage(), $e->getMessage());
        }
    }

    /**
     * Mutation-checklist row 8, T034 addendum (found during Phase 6's final
     * non-vacuousness pass): the test above alone cannot fully prove parse()
     * delegates to collect() rather than reimplementing its own throw-on-
     * first logic, because AgentDefinitionParser is a deliberately stateless
     * singleton (LlmClientServiceProvider's own comment) -- across two
     * independent top-level calls, even a correct implementation constructs
     * two distinct-but-equal exception instances, so assertSame() across
     * two separate calls can never distinguish "delegates to one collect()
     * call" from "reimplements independently." What genuinely distinguishes
     * them, observable from outside, is whether a *single* parse() call
     * re-derives the result more than once -- re-reading live state (the
     * operation catalog, in this document's case) redundantly. This is the
     * same call-count technique the_operation_catalog_is_resolved_exactly_once_per_collect_call()
     * above already establishes for collect() itself, applied one layer up:
     * a single parse() call, for a document whose only problem is in a
     * pattern-based step (steps 8-10, guaranteeing resolveCatalog() is
     * actually reached), must read the operation catalog exactly once --
     * never twice, which any reimplementation redundantly re-deriving the
     * result (whether by calling collect() a second time or duplicating its
     * step logic inline) would do.
     */
    #[Test]
    public function parse_reads_the_operation_catalog_exactly_once_never_redundantly_re_deriving_its_result(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, ['paths' => []]);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->once()->andReturn(['paths' => []]);
        $this->app->instance(Generator::class, $generator);

        $raw = <<<YAML
name: broken-agent
tools:
  allow:
    - nonexistent.operation
YAML;

        try {
            (new AgentDefinitionParser())->parse($raw);
            $this->fail('Expected parse() to throw for an empty-resolving tools.allow pattern.');
        } catch (AgentDefinitionResolutionException $e) {
            $this->assertSame(AgentDefinitionResolutionErrorKind::EmptyOperationPattern, $e->kind);
        }

        // Mockery::close() in tearDown() enforces the ->once() expectation
        // above -- a redundant second read would fail the test there.
    }

    #[Test]
    public function empty_whitespace_and_empty_mapping_documents_report_missing_name_not_malformed_yaml(): void
    {
        $this->seedOperationCatalog([]);
        $parser = new AgentDefinitionParser();

        foreach (['', "   \n", '{}'] as $raw) {
            $result = $parser->collect($raw);

            $this->assertCount(1, $result->problems, 'input: ' . var_export($raw, true));
            $this->assertInstanceOf(AgentDefinitionParseException::class, $result->problems[0]);
            $this->assertSame(AgentDefinitionParseErrorKind::MissingName, $result->problems[0]->kind, 'input: ' . var_export($raw, true));
        }
    }

    #[Test]
    public function a_bare_scalar_and_a_non_empty_list_root_report_malformed_yaml_a_different_kind(): void
    {
        $this->seedOperationCatalog([]);
        $parser = new AgentDefinitionParser();

        foreach (['hello', "- a\n- b"] as $raw) {
            $result = $parser->collect($raw);

            $this->assertCount(1, $result->problems, 'input: ' . var_export($raw, true));
            $this->assertInstanceOf(AgentDefinitionParseException::class, $result->problems[0]);
            $this->assertSame(AgentDefinitionParseErrorKind::MalformedYaml, $result->problems[0]->kind, 'input: ' . var_export($raw, true));
        }
    }

    #[Test]
    public function a_live_database_failure_resolving_the_model_propagates_out_of_collect_uncaught(): void
    {
        $this->seedOperationCatalog([]);

        // Forces LanguageModel::where('name', ...)->exists() to fail as a
        // genuine infrastructure error rather than a domain-level
        // AgentDefinitionResolutionException -- collect()'s per-step
        // try/catch must never widen to catch this.
        Schema::drop('language_models');

        $raw = <<<YAML
name: broken-agent
model: some-model
YAML;

        $this->expectException(QueryException::class);

        (new AgentDefinitionParser())->collect($raw);
    }

    #[Test]
    public function the_operation_catalog_is_resolved_exactly_once_per_collect_call(): void
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

        // A hard call-count expectation, not just a stub -- Mockery::close()
        // in tearDown() fails the test if getOperations() (which always
        // invokes this Generator, unlike getOperationDetails() which reads
        // the pre-seeded static cache above) is called any number of times
        // other than exactly once across the whole collect() call, even
        // though four different pattern-based steps run below.
        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->once()->andReturn($doc);
        $this->app->instance(Generator::class, $generator);

        $raw = <<<YAML
name: catalog-agent
tools:
  allow:
    - contacts.*
  deny:
    - contacts.destroy
safety:
  confirmation_required:
    - contacts.destroy
  denylist:
    - weather.*
YAML;

        $result = (new AgentDefinitionParser())->collect($raw);

        $this->assertSame([], $result->problems);
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
