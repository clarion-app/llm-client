<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Exceptions\SequenceDefinitionValidationException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageSequenceDefinition;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\SequenceService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 105-stage-pipeline, Phase 3 (US1), tasks.md T019.
 *
 * Unit tests for the not-yet-built `SequenceService::defineSequence()`
 * (contracts/stage-pipeline-api.md §1, data-model.md §1/§2/§8).
 *
 * Written before defineSequence() exists -- every test below is expected
 * to FAIL red (method not found) until T024 lands.
 */
class SequenceServiceDefineTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('stage_results')->delete();
        DB::table('sequence_runs')->delete();
        DB::table('stages')->delete();
        DB::table('stage_sequence_definitions')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];

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

    private function makeAgent(string $name, ?string $userId = null): Agent
    {
        return app(AgentService::class)->create($userId ?? $this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    // =================================================================

    #[Test]
    public function creates_a_definition_with_ordered_stage_rows_from_array_input(): void
    {
        $coordinator = $this->makeAgent('coordinator-happy');
        $helperOne = $this->makeAgent('helper-one');
        $helperTwo = $this->makeAgent('helper-two');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helperOne->id);
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helperTwo->id);

        $definition = app(SequenceService::class)->defineSequence(
            $this->user->id,
            'Draft, check',
            'A two-stage pipeline.',
            $coordinator->id,
            [
                ['name' => 'Draft', 'helper_agent_id' => $helperOne->id],
                ['name' => 'Check', 'helper_agent_id' => $helperTwo->id, 'is_idempotent' => true],
            ],
        );

        $this->assertInstanceOf(StageSequenceDefinition::class, $definition);
        $this->assertSame($this->user->id, $definition->owner_user_id);
        $this->assertSame($coordinator->id, $definition->coordinator_agent_id);
        $this->assertSame('Draft, check', $definition->name);
        $this->assertSame('A two-stage pipeline.', $definition->description);

        $stages = Stage::where('sequence_definition_id', $definition->id)->orderBy('position')->get();
        $this->assertCount(2, $stages);

        $this->assertSame(1, $stages[0]->position);
        $this->assertSame('Draft', $stages[0]->name);
        $this->assertSame($helperOne->id, $stages[0]->helper_agent_id);
        $this->assertFalse($stages[0]->is_idempotent);

        $this->assertSame(2, $stages[1]->position);
        $this->assertSame('Check', $stages[1]->name);
        $this->assertSame($helperTwo->id, $stages[1]->helper_agent_id);
        $this->assertTrue($stages[1]->is_idempotent);
    }

    #[Test]
    public function refuses_an_empty_stages_array(): void
    {
        $coordinator = $this->makeAgent('coordinator-empty');

        try {
            app(SequenceService::class)->defineSequence($this->user->id, 'Empty', null, $coordinator->id, []);
            $this->fail('expected a SequenceDefinitionValidationException for an empty stages array');
        } catch (SequenceDefinitionValidationException $e) {
            $this->assertSame('empty_stages', $e->errorCode);
        }

        $this->assertSame(0, StageSequenceDefinition::count(), 'a refused defineSequence() call must never create a row');
    }

    #[Test]
    public function refuses_an_unknown_coordinator_agent(): void
    {
        $otherUser = User::factory()->create();
        $notOwned = $this->makeAgent('not-owned-coordinator', $otherUser->id);

        try {
            app(SequenceService::class)->defineSequence($this->user->id, 'Bad coordinator', null, $notOwned->id, [
                ['name' => 'Stage', 'helper_agent_id' => $notOwned->id],
            ]);
            $this->fail('expected a SequenceDefinitionValidationException for an unowned coordinator_agent_id');
        } catch (SequenceDefinitionValidationException $e) {
            $this->assertSame('unknown_coordinator_agent', $e->errorCode);
        }

        $this->assertSame(0, StageSequenceDefinition::count());
    }

    #[Test]
    public function refuses_an_inactive_coordinator_agent(): void
    {
        $coordinator = $this->makeAgent('coordinator-inactive');
        $coordinator->is_active = false;
        $coordinator->save();

        try {
            app(SequenceService::class)->defineSequence($this->user->id, 'Bad coordinator', null, $coordinator->id, [
                ['name' => 'Stage', 'helper_agent_id' => $coordinator->id],
            ]);
            $this->fail('expected a SequenceDefinitionValidationException for an inactive coordinator agent');
        } catch (SequenceDefinitionValidationException $e) {
            $this->assertSame('unknown_coordinator_agent', $e->errorCode);
        }

        $this->assertSame(0, StageSequenceDefinition::count());
    }

    #[Test]
    public function refuses_an_unknown_helper_agent_naming_the_offending_stage_position(): void
    {
        $coordinator = $this->makeAgent('coordinator-unknown-helper');
        $helperOne = $this->makeAgent('helper-good');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helperOne->id);

        // Deliberately never assigned to $coordinator, so it fails the
        // AgentHelperAssignment check even though it is a real, owned,
        // active agent.
        $notAssigned = $this->makeAgent('helper-not-assigned');

        try {
            app(SequenceService::class)->defineSequence($this->user->id, 'Bad helper', null, $coordinator->id, [
                ['name' => 'Good stage', 'helper_agent_id' => $helperOne->id],
                ['name' => 'Bad stage', 'helper_agent_id' => $notAssigned->id],
            ]);
            $this->fail('expected a SequenceDefinitionValidationException for an unassigned helper_agent_id');
        } catch (SequenceDefinitionValidationException $e) {
            $this->assertSame('unknown_helper_agent', $e->errorCode);
            $this->assertSame(2, $e->stagePosition, 'must name the offending stage position, not just "some stage"');
        }

        $this->assertSame(0, StageSequenceDefinition::count(), 'a refused defineSequence() call must never create a row, even a partial one');
        $this->assertSame(0, Stage::count(), 'no Stage row may be created for the earlier, otherwise-valid stage either');
    }

    #[Test]
    public function refuses_a_not_owned_helper_agent(): void
    {
        $coordinator = $this->makeAgent('coordinator-foreign-helper');
        $otherUser = User::factory()->create();
        $foreignHelper = $this->makeAgent('foreign-helper', $otherUser->id);

        try {
            app(SequenceService::class)->defineSequence($this->user->id, 'Foreign helper', null, $coordinator->id, [
                ['name' => 'Stage one', 'helper_agent_id' => $foreignHelper->id],
            ]);
            $this->fail('expected a SequenceDefinitionValidationException for a not-owned helper_agent_id');
        } catch (SequenceDefinitionValidationException $e) {
            $this->assertSame('unknown_helper_agent', $e->errorCode);
            $this->assertSame(1, $e->stagePosition);
        }
    }

    #[Test]
    public function refuses_an_invalid_schema_naming_the_offending_stage_position(): void
    {
        $coordinator = $this->makeAgent('coordinator-bad-schema');
        $helper = $this->makeAgent('helper-bad-schema');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        try {
            app(SequenceService::class)->defineSequence($this->user->id, 'Bad schema', null, $coordinator->id, [
                ['name' => 'Stage one', 'helper_agent_id' => $helper->id, 'output_schema' => ['type' => 'not_a_real_json_schema_type']],
            ]);
            $this->fail('expected a SequenceDefinitionValidationException for a malformed output_schema');
        } catch (SequenceDefinitionValidationException $e) {
            $this->assertSame('invalid_schema', $e->errorCode);
            $this->assertSame(1, $e->stagePosition);
        }

        $this->assertSame(0, StageSequenceDefinition::count());
    }

    #[Test]
    public function a_well_formed_schema_that_merely_requires_a_property_is_not_flagged_invalid(): void
    {
        $coordinator = $this->makeAgent('coordinator-good-schema');
        $helper = $this->makeAgent('helper-good-schema');
        app(AgentHelperService::class)->assign($this->user->id, $coordinator->id, $helper->id);

        // A schema requiring a property {} lacks is a perfectly
        // well-formed JSON Schema -- it must NOT be flagged invalid_schema
        // just because an empty probe payload doesn't satisfy it.
        $definition = app(SequenceService::class)->defineSequence($this->user->id, 'Good schema', null, $coordinator->id, [
            [
                'name' => 'Stage one',
                'helper_agent_id' => $helper->id,
                'output_schema' => ['type' => 'object', 'required' => ['draft_text'], 'properties' => ['draft_text' => ['type' => 'string']]],
            ],
        ]);

        $this->assertInstanceOf(StageSequenceDefinition::class, $definition);
    }
}
