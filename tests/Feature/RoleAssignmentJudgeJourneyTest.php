<?php

namespace Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An operator must be able to assign, view, and connectivity-test a
 * `judge` role through the exact same route surface `inference` and
 * `embedding` already use — no new endpoint, no new controller, no
 * special-casing anywhere in the existing role-assignment machinery.
 */
class RoleAssignmentJudgeJourneyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_server_statuses')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();
        \Mockery::close();
        parent::tearDown();
    }

    private function makeServer(string $name): Server
    {
        return Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'server_url' => 'https://'.Str::slug($name).'.example.com',
            'provider_type' => 'openai',
        ]);
    }

    /**
     * Registers a provider whose chat() call always succeeds, so a
     * connectivity test against a working judge assignment can reach a
     * genuine pass outcome rather than failing for an unrelated reason.
     */
    private function registerWorkingProvider(): void
    {
        /** @var ProviderRegistry $registry */
        $registry = app(ProviderRegistry::class);

        $registry->register(ProviderType::OpenAI, function (Server $server) {
            $provider = $this->createMock(LlmProvider::class);
            $provider->method('chat')->willReturn([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'pong']]],
            ]);

            return $provider;
        });
    }

    #[Test]
    public function put_role_assignment_accepts_the_judge_role_exactly_like_inference_and_embedding(): void
    {
        $server = $this->makeServer('Judge Server');

        $response = $this->actingAs($this->user)->putJson('/api/clarion-app/llm-client/role-assignment', [
            'role' => 'judge',
            'scope' => 'installation',
            'server_id' => $server->id,
            'model' => 'gpt-4o-mini',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'role' => 'judge',
        ]);
        $response->assertJsonPath('effective.status', 'resolved');
        $response->assertJsonPath('effective.model', 'gpt-4o-mini');

        $this->assertDatabaseHas('llm_role_assignments', [
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'gpt-4o-mini',
        ]);
    }

    #[Test]
    public function get_role_assignment_includes_a_judge_key_with_zero_special_casing(): void
    {
        // No RoleAssignment for `judge` exists at all — describeAllRoles()
        // must still list the role (as unassigned) purely because it is
        // now one of ModelRole::cases(), not because of any per-role
        // branch written for it.
        $response = $this->actingAs($this->user)->getJson('/api/clarion-app/llm-client/role-assignment');

        $response->assertStatus(200);
        $response->assertJsonStructure(['judge' => ['role', 'effective', 'user_assignment', 'installation_assignment']]);
        $response->assertJsonPath('judge.role', 'judge');
        $response->assertJsonPath('judge.effective.status', 'unassigned');
    }

    #[Test]
    public function delete_role_assignment_clears_a_judge_assignment(): void
    {
        $server = $this->makeServer('Judge Server To Clear');

        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'gpt-4o-mini',
        ]);

        $response = $this->actingAs($this->user)->deleteJson('/api/clarion-app/llm-client/role-assignment', [
            'role' => 'judge',
            'scope' => 'installation',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('effective.status', 'unassigned');

        // RoleAssignmentService::clear() soft-deletes (RoleAssignment uses
        // EloquentMultiChainBridge, which forces SoftDeletes internally,
        // and set()'s own withTrashed()/restore() logic depends on the
        // trashed row surviving so a later re-assignment doesn't collide
        // with the ['role', 'user_id'] unique constraint — the exact
        // behavior RoleAssignmentServiceTest::clear_actually_soft_deletes
        // already pins for every role). assertSoftDeleted is the correct
        // check for that: the row is gone from every normal query (which
        // is what "cleared" means from an operator's point of view) while
        // still physically present with deleted_at set.
        $this->assertSoftDeleted('llm_role_assignments', [
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
        ]);
    }

    #[Test]
    public function post_role_assignment_test_with_a_working_judge_assignment_returns_a_pass_shaped_result_never_a_422(): void
    {
        $server = $this->makeServer('Judge Test Server');
        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => $this->user->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o-mini',
        ]);

        $this->registerWorkingProvider();

        $response = $this->actingAs($this->user)
            ->postJson('/api/clarion-app/llm-client/role-assignment/test', ['role' => 'judge']);

        // Must never be rejected by validation (the gap: Rule::in() in
        // RoleAssignmentController::test() not yet accepting 'judge'),
        // and must never blow up with an UnhandledMatchError/LogicException
        // from RoleTestRunner's exercise() match not yet having a Judge arm.
        $response->assertStatus(200);
        $response->assertJsonStructure(['role', 'outcome', 'model', 'server', 'message', 'duration_ms']);
        $response->assertJsonPath('role', 'judge');
        $response->assertJsonPath('outcome', 'pass');
    }
}
