<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\SchedulerTrigger;
use ClarionApp\LlmClient\Models\SchedulerTriggerFiring;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HTTP-level coverage for SchedulerTriggerController's five routes: create
 * (with its validation rules and its one-and-only retry_limit default
 * application), list, read, partial update (kind immutable), and soft
 * delete. Mirrors CodingProjectControllerTest's own shape -- actingAs() over
 * the real registered routes, never Eloquent-only.
 */
class SchedulerTriggerControllerTest extends TestCase
{
    private User $user;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_runs')->delete();
        DB::table('scheduler_trigger_firings')->delete();
        DB::table('scheduler_triggers')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    private function seedOperationCatalog(array $operations = []): void
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

    private function schedulerYaml(array $toolsAllow = []): string
    {
        $allow = empty($toolsAllow)
            ? ' []'
            : "\n".implode("\n", array_map(fn (string $op) => "    - {$op}", $toolsAllow));

        return "format_version: \"1.0\"\n"
            ."name: scheduler\n"
            ."version: \"1\"\n"
            ."instructions: |\n"
            ."  Test scheduler agent.\n"
            ."capabilities: []\n"
            ."tools:\n"
            ."  allow:{$allow}\n"
            ."  deny: []\n"
            ."safety:\n"
            ."  confirmation_required: []\n"
            ."  unattended_authorized: []\n"
            ."  denylist: []\n";
    }

    private function createSchedulerAgent(User $user, array $toolsAllow = []): Agent
    {
        $agent = Agent::create([
            'user_id' => $user->id,
            'name' => 'scheduler',
            'current_version_id' => null,
        ]);

        $yaml = $this->schedulerYaml($toolsAllow);
        $version = AgentVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 1,
            'raw_definition' => $yaml,
            'content_hash' => hash('sha256', $yaml),
            'source' => AgentChangeSource::Created->value,
            'changed_by_user_id' => $user->id,
        ]);

        $agent->current_version_id = $version->id;
        $agent->save();

        return $agent->fresh(['currentVersion']);
    }

    private function createNonSchedulerAgent(User $user): Agent
    {
        return Agent::create([
            'user_id' => $user->id,
            'name' => 'research',
            'current_version_id' => null,
        ]);
    }

    private function schedulePayload(string $agentId, array $overrides = []): array
    {
        return array_merge([
            'agent_id' => $agentId,
            'name' => 'Daily digest',
            'kind' => SchedulerTrigger::KIND_SCHEDULE,
            'schedule_expression' => '0 9 * * *',
            'defined_work' => 'Send the daily digest.',
        ], $overrides);
    }

    private function conditionPayload(string $agentId, array $overrides = []): array
    {
        return array_merge([
            'agent_id' => $agentId,
            'name' => 'Weather watch',
            'kind' => SchedulerTrigger::KIND_CONDITION,
            'condition_operation_id' => 'weather.check',
            'condition_path' => 'data.rain',
            'condition_comparator' => 'eq',
            'condition_value' => 'true',
            'defined_work' => 'Alert about rain.',
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // POST scheduler-triggers (store)
    // -----------------------------------------------------------------

    #[Test]
    public function store_creates_a_schedule_trigger(): void
    {
        $agent = $this->createSchedulerAgent($this->user);

        $response = $this->actingAs($this->user)->postJson(
            $this->apiUrl('scheduler-triggers'),
            $this->schedulePayload($agent->id),
        );

        $response->assertStatus(201);
        $data = $response->json();

        $this->assertSame('Daily digest', $data['name']);
        $this->assertSame(SchedulerTrigger::KIND_SCHEDULE, $data['kind']);

        $trigger = SchedulerTrigger::find($data['id']);
        $this->assertNotNull($trigger);
        $this->assertSame($this->user->id, $trigger->user_id);
        $this->assertSame($agent->id, $trigger->agent_id);
    }

    #[Test]
    public function store_creates_a_condition_trigger_when_the_operation_is_permitted(): void
    {
        $this->seedOperationCatalog([
            'weather.check' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Check the weather'],
        ]);
        $agent = $this->createSchedulerAgent($this->user, ['weather.check']);

        $response = $this->actingAs($this->user)->postJson(
            $this->apiUrl('scheduler-triggers'),
            $this->conditionPayload($agent->id),
        );

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertSame(SchedulerTrigger::KIND_CONDITION, $data['kind']);
        $this->assertSame('weather.check', $data['condition_operation_id']);
    }

    #[Test]
    public function store_422s_when_agent_id_does_not_reference_a_scheduler_named_agent(): void
    {
        $nonScheduler = $this->createNonSchedulerAgent($this->user);

        $response = $this->actingAs($this->user)->postJson(
            $this->apiUrl('scheduler-triggers'),
            $this->schedulePayload($nonScheduler->id),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['agent_id']);
        $this->assertSame(0, SchedulerTrigger::count());
    }

    #[Test]
    public function store_422s_when_agent_id_belongs_to_a_different_user(): void
    {
        $othersAgent = $this->createSchedulerAgent($this->otherUser);

        $response = $this->actingAs($this->user)->postJson(
            $this->apiUrl('scheduler-triggers'),
            $this->schedulePayload($othersAgent->id),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['agent_id']);
        $this->assertSame(0, SchedulerTrigger::count());
    }

    #[Test]
    public function store_422s_when_a_schedule_trigger_is_missing_its_schedule_expression(): void
    {
        $agent = $this->createSchedulerAgent($this->user);

        $response = $this->actingAs($this->user)->postJson(
            $this->apiUrl('scheduler-triggers'),
            $this->schedulePayload($agent->id, ['schedule_expression' => null]),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['schedule_expression']);
    }

    #[Test]
    public function store_422s_when_a_schedule_trigger_carries_condition_fields(): void
    {
        $agent = $this->createSchedulerAgent($this->user);

        $response = $this->actingAs($this->user)->postJson(
            $this->apiUrl('scheduler-triggers'),
            $this->schedulePayload($agent->id, ['condition_operation_id' => 'weather.check']),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['condition_operation_id']);
    }

    #[Test]
    public function store_422s_when_a_condition_trigger_is_missing_a_required_condition_field(): void
    {
        $this->seedOperationCatalog([
            'weather.check' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Check the weather'],
        ]);
        $agent = $this->createSchedulerAgent($this->user, ['weather.check']);

        $response = $this->actingAs($this->user)->postJson(
            $this->apiUrl('scheduler-triggers'),
            $this->conditionPayload($agent->id, ['condition_comparator' => null]),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['condition_comparator']);
    }

    #[Test]
    public function store_422s_when_a_condition_trigger_carries_a_schedule_expression(): void
    {
        $this->seedOperationCatalog([
            'weather.check' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Check the weather'],
        ]);
        $agent = $this->createSchedulerAgent($this->user, ['weather.check']);

        $response = $this->actingAs($this->user)->postJson(
            $this->apiUrl('scheduler-triggers'),
            $this->conditionPayload($agent->id, ['schedule_expression' => '0 9 * * *']),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['schedule_expression']);
    }

    #[Test]
    public function store_422s_when_condition_operation_id_is_not_permitted_by_the_bound_agent_definition(): void
    {
        $this->seedOperationCatalog([
            'weather.check' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Check the weather'],
        ]);
        // tools.allow left empty -- weather.check is not permitted.
        $agent = $this->createSchedulerAgent($this->user, []);

        $response = $this->actingAs($this->user)->postJson(
            $this->apiUrl('scheduler-triggers'),
            $this->conditionPayload($agent->id),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['condition_operation_id']);
        $this->assertSame(0, SchedulerTrigger::count());
    }

    #[Test]
    public function store_persists_the_configured_default_retry_limit_when_omitted(): void
    {
        $this->app['config']->set('llm-client.scheduler.default_retry_limit', 7);
        $agent = $this->createSchedulerAgent($this->user);

        $payload = $this->schedulePayload($agent->id);
        unset($payload['retry_limit']);

        $response = $this->actingAs($this->user)->postJson($this->apiUrl('scheduler-triggers'), $payload);

        $response->assertStatus(201);
        $trigger = SchedulerTrigger::find($response->json('id'));
        $this->assertSame(7, $trigger->retry_limit);
    }

    #[Test]
    public function store_persists_an_explicit_zero_retry_limit_exactly_never_coerced_to_the_default(): void
    {
        $this->app['config']->set('llm-client.scheduler.default_retry_limit', 3);
        $agent = $this->createSchedulerAgent($this->user);

        $response = $this->actingAs($this->user)->postJson(
            $this->apiUrl('scheduler-triggers'),
            $this->schedulePayload($agent->id, ['retry_limit' => 0]),
        );

        $response->assertStatus(201);
        $trigger = SchedulerTrigger::find($response->json('id'));
        $this->assertSame(0, $trigger->retry_limit);
    }

    // -----------------------------------------------------------------
    // GET scheduler-triggers (index)
    // -----------------------------------------------------------------

    #[Test]
    public function index_returns_only_the_callers_own_non_trashed_triggers(): void
    {
        $agent = $this->createSchedulerAgent($this->user);
        $othersAgent = $this->createSchedulerAgent($this->otherUser);

        $mine = SchedulerTrigger::create($this->triggerAttributes($this->user->id, $agent->id));
        $theirs = SchedulerTrigger::create($this->triggerAttributes($this->otherUser->id, $othersAgent->id));
        $trashed = SchedulerTrigger::create($this->triggerAttributes($this->user->id, $agent->id));
        $trashed->delete();

        $response = $this->actingAs($this->user)->getJson($this->apiUrl('scheduler-triggers'));

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id), 'another user\'s trigger must never be listed');
        $this->assertFalse($ids->contains($trashed->id), 'a soft-deleted trigger must never be listed');
    }

    // -----------------------------------------------------------------
    // GET scheduler-triggers/{id} (show)
    // -----------------------------------------------------------------

    #[Test]
    public function show_returns_a_trigger_owned_by_the_caller(): void
    {
        $agent = $this->createSchedulerAgent($this->user);
        $trigger = SchedulerTrigger::create($this->triggerAttributes($this->user->id, $agent->id));

        $response = $this->actingAs($this->user)->getJson($this->apiUrl("scheduler-triggers/{$trigger->id}"));

        $response->assertStatus(200);
        $this->assertSame($trigger->id, $response->json('id'));
    }

    #[Test]
    public function show_404s_uniformly_for_an_absent_id(): void
    {
        $response = $this->actingAs($this->user)->getJson(
            $this->apiUrl('scheduler-triggers/'.(string) Str::uuid()),
        );

        $response->assertStatus(404);
        $response->assertJsonMissing(['error' => null]);
    }

    #[Test]
    public function show_404s_uniformly_for_a_foreign_owned_id_never_a_distinguishing_403(): void
    {
        $othersAgent = $this->createSchedulerAgent($this->otherUser);
        $theirs = SchedulerTrigger::create($this->triggerAttributes($this->otherUser->id, $othersAgent->id));

        $response = $this->actingAs($this->user)->getJson($this->apiUrl("scheduler-triggers/{$theirs->id}"));

        $response->assertStatus(404);

        $absentResponse = $this->actingAs($this->user)->getJson(
            $this->apiUrl('scheduler-triggers/'.(string) Str::uuid()),
        );

        $this->assertSame(
            $absentResponse->json(),
            $response->json(),
            'a foreign-owned trigger must be indistinguishable from a genuinely absent one',
        );
    }

    // -----------------------------------------------------------------
    // PUT scheduler-triggers/{id} (update)
    // -----------------------------------------------------------------

    #[Test]
    public function update_allows_a_partial_update(): void
    {
        $agent = $this->createSchedulerAgent($this->user);
        $trigger = SchedulerTrigger::create($this->triggerAttributes($this->user->id, $agent->id));

        $response = $this->actingAs($this->user)->putJson(
            $this->apiUrl("scheduler-triggers/{$trigger->id}"),
            ['is_active' => false, 'retry_limit' => 5],
        );

        $response->assertStatus(200);
        $trigger->refresh();
        $this->assertFalse($trigger->is_active);
        $this->assertSame(5, $trigger->retry_limit);
        // Untouched fields survive the partial update.
        $this->assertSame('0 9 * * *', $trigger->schedule_expression);
    }

    #[Test]
    public function update_422s_on_an_attempt_to_change_kind(): void
    {
        $agent = $this->createSchedulerAgent($this->user);
        $trigger = SchedulerTrigger::create($this->triggerAttributes($this->user->id, $agent->id));

        $response = $this->actingAs($this->user)->putJson(
            $this->apiUrl("scheduler-triggers/{$trigger->id}"),
            ['kind' => SchedulerTrigger::KIND_CONDITION],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['kind']);
        $trigger->refresh();
        $this->assertSame(SchedulerTrigger::KIND_SCHEDULE, $trigger->kind);
    }

    #[Test]
    public function update_404s_for_a_foreign_owned_trigger_and_changes_nothing(): void
    {
        $othersAgent = $this->createSchedulerAgent($this->otherUser);
        $theirs = SchedulerTrigger::create($this->triggerAttributes($this->otherUser->id, $othersAgent->id));

        $response = $this->actingAs($this->user)->putJson(
            $this->apiUrl("scheduler-triggers/{$theirs->id}"),
            ['is_active' => false],
        );

        $response->assertStatus(404);
        $theirs->refresh();
        $this->assertTrue($theirs->is_active);
    }

    // -----------------------------------------------------------------
    // DELETE scheduler-triggers/{id} (destroy)
    // -----------------------------------------------------------------

    #[Test]
    public function destroy_soft_deletes_leaving_firings_and_runs_intact_and_queryable(): void
    {
        $agent = $this->createSchedulerAgent($this->user);
        $trigger = SchedulerTrigger::create($this->triggerAttributes($this->user->id, $agent->id));

        $run = AgentRun::create([
            'kind' => RunKind::SystemInitiated,
            'user_id' => $this->user->id,
            'end_state' => 'completed',
            'started_at' => now(),
            'ended_at' => now(),
            'duration_ms' => 100,
            'step_count' => 1,
        ]);

        $firing = SchedulerTriggerFiring::create([
            'trigger_id' => $trigger->id,
            'fire_key' => 'schedule:'.$trigger->id.':2026-08-17T09:00',
            'run_id' => $run->id,
        ]);

        $response = $this->actingAs($this->user)->deleteJson($this->apiUrl("scheduler-triggers/{$trigger->id}"));

        $response->assertStatus(204);

        $this->assertNull(SchedulerTrigger::find($trigger->id));
        $trashed = SchedulerTrigger::withTrashed()->find($trigger->id);
        $this->assertNotNull($trashed, 'the row must still exist after a soft delete');
        $this->assertNotNull($trashed->deleted_at);

        // Historical rows remain intact and queryable.
        $this->assertNotNull(SchedulerTriggerFiring::find($firing->id));
        $this->assertSame($run->id, SchedulerTriggerFiring::find($firing->id)->run_id);
        $this->assertNotNull(AgentRun::find($run->id));
    }

    #[Test]
    public function destroy_404s_for_a_foreign_owned_trigger_and_does_not_delete_it(): void
    {
        $othersAgent = $this->createSchedulerAgent($this->otherUser);
        $theirs = SchedulerTrigger::create($this->triggerAttributes($this->otherUser->id, $othersAgent->id));

        $response = $this->actingAs($this->user)->deleteJson($this->apiUrl("scheduler-triggers/{$theirs->id}"));

        $response->assertStatus(404);
        $this->assertNotNull(SchedulerTrigger::find($theirs->id));
    }

    private function triggerAttributes(string $userId, string $agentId): array
    {
        return [
            'user_id' => $userId,
            'agent_id' => $agentId,
            'name' => 'Fixture trigger',
            'kind' => SchedulerTrigger::KIND_SCHEDULE,
            'schedule_expression' => '0 9 * * *',
            'defined_work' => 'Do the fixture work.',
            'retry_limit' => 3,
            'is_active' => true,
        ];
    }
}
