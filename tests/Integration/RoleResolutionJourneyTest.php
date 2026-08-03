<?php

namespace Tests\Integration;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\EmbeddingService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RoleResolutionStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end journey tests for US1 acceptance scenarios.
 *
 * Validates the complete role resolution flow against the assembled system:
 * migration from UserSetting, assignment recording, inference usage in
 * conversation creation, embedding usage with transport verification,
 * clearing fallback, and unassigned-state reporting.
 */
class RoleResolutionJourneyTest extends AssembledSystemTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createLlmUserSettingsTable();
    }

    protected function tearDown(): void
    {
        // Clean up data BEFORE parent::tearDown() closes the DB connection.
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_user_settings')->delete();
        DB::table('language_models')->delete();
        DB::table('llm_servers')->delete();
        DB::table('conversations')->delete();
        DB::table('messages')->delete();
        DB::table('users')->delete();

        // Reset config values that tests may have modified.
        config(['llm-client.memory.embedding.server_id' => null]);
        config(['llm-client.memory.embedding.model' => null]);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------------
     * Helper: create llm_user_settings table (not in shared schema bootstrap)
     * ------------------------------------------------------------------------ */

    private function createLlmUserSettingsTable(): void
    {
        if (!Schema::hasTable('llm_user_settings')) {
            Schema::create('llm_user_settings', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->unique();
                $table->uuid('server_id')->nullable();
                $table->string('model')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    /* ------------------------------------------------------------------------
     * Helper: simulate the migration command (mirrors MigrateUserSettingsCommand)
     * ------------------------------------------------------------------------ */

    private function runMigration(): void
    {
        DB::table('llm_user_settings')
            ->whereNull('deleted_at')
            ->whereNotNull('server_id')
            ->whereNotNull('model')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $exists = DB::table('llm_role_assignments')
                        ->where('role', 'inference')
                        ->where('user_id', $row->user_id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('llm_role_assignments')->insert([
                        'id' => (string) Str::uuid(),
                        'role' => 'inference',
                        'user_id' => $row->user_id,
                        'server_id' => $row->server_id,
                        'model' => $row->model,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    /* ------------------------------------------------------------------------
     * Helper: simulate ConversationController::store() model resolution logic
     * ------------------------------------------------------------------------ */

    /**
     * Simulate the model resolution in ConversationController::store().
     *
     * Returns the server_id and model that would be used for a new conversation,
     * or null if the controller would return a 422 response.
     */
    private function resolveInferenceForConversation(string $userId, ?string $explicitServerId = null, ?string $explicitModel = null): ?array
    {
        $serverId = $explicitServerId;
        $modelName = $explicitModel;

        if (!$serverId || !$modelName) {
            $resolver = $this->app->make(RoleResolver::class);
            $resolution = $resolver->resolve(ModelRole::Inference, $userId);

            if ($resolution->hasEffectiveModel()) {
                $serverId = $serverId ?: $resolution->server->id;
                $modelName = $modelName ?: $resolution->model;
            }
        }

        if (!$serverId || !$modelName) {
            return null; // Would be a 422 response.
        }

        return ['server_id' => $serverId, 'model' => $modelName];
    }

    // ========================================================================
    // US1 Acceptance Scenario 1:
    // Saved UserSetting appears as inference assignment post-migration.
    // ========================================================================

    #[Test]
    public function us1_scenario1_saved_user_setting_becomes_inference_assignment(): void
    {
        $this->scenario = 'us1_saved_setting_migration';

        $resolver = $this->app->make(RoleResolver::class);

        // Create a server and a language model.
        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Default Server',
            'server_url' => 'https://default.example.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);
        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'name' => 'gpt-4',
            'server_id' => $server->id,
        ]);

        // Create a user with a saved default server and model.
        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Migrated User',
            'email' => 'migrated@example.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);

        DB::table('llm_user_settings')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'server_id' => $server->id,
            'model' => 'gpt-4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run migration.
        $this->runMigration();

        // Verify: inference role resolves to the migrated server/model.
        $resolution = $resolver->resolve(ModelRole::Inference, $user->id);
        $this->assertEquals(RoleResolutionStatus::Resolved, $resolution->status);
        $this->assertEquals('user', $resolution->scope);
        $this->assertEquals($server->id, $resolution->server->id);
        $this->assertEquals('gpt-4', $resolution->model);

        // Verify: the assignment row exists in the database.
        $assignment = RoleAssignment::where('role', 'inference')
            ->where('user_id', $user->id)
            ->first();
        $this->assertNotNull($assignment);
        $this->assertEquals($server->id, $assignment->server_id);
        $this->assertEquals('gpt-4', $assignment->model);

        // Verify: conversation resolution uses the migrated assignment.
        $resolved = $this->resolveInferenceForConversation($user->id);
        $this->assertNotNull($resolved);
        $this->assertEquals($server->id, $resolved['server_id']);
        $this->assertEquals('gpt-4', $resolved['model']);
    }

    // ========================================================================
    // US1 Acceptance Scenario 2:
    // User assigns an embedding model and it is recorded and reported back.
    // ========================================================================

    #[Test]
    public function us1_scenario2_embedding_assignment_recorded_and_reported(): void
    {
        $this->scenario = 'us1_embedding_assignment';

        $service = $this->app->make(RoleAssignmentService::class);
        $resolver = $this->app->make(RoleResolver::class);

        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Embedding Server',
            'server_url' => 'https://embedding.example.com/v1/embeddings',
            'provider_type' => 'openai',
        ]);
        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'name' => 'text-embedding-3-small',
            'server_id' => $server->id,
        ]);

        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Embedding User',
            'email' => 'embedding@example.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);

        // Assign embedding model at user scope.
        $service->set(ModelRole::Embedding, $user->id, $server->id, 'text-embedding-3-small');

        // Verify: assignment is recorded in the database.
        $assignment = RoleAssignment::where('role', 'embedding')
            ->where('user_id', $user->id)
            ->first();
        $this->assertNotNull($assignment);
        $this->assertEquals($server->id, $assignment->server_id);
        $this->assertEquals('text-embedding-3-small', $assignment->model);

        // Verify: resolver returns the assigned model.
        $resolution = $resolver->resolve(ModelRole::Embedding, $user->id);
        $this->assertEquals(RoleResolutionStatus::Resolved, $resolution->status);
        $this->assertEquals('user', $resolution->scope);
        $this->assertEquals('text-embedding-3-small', $resolution->model);

        // Verify: describeAllRoles reports the assignment correctly.
        $described = $service->describeAllRoles($user->id);
        $this->assertEquals('resolved', $described['embedding']['effective']['status']);
        $this->assertEquals('user', $described['embedding']['effective']['scope']);
        $this->assertEquals('text-embedding-3-small', $described['embedding']['effective']['model']);
        $this->assertNotNull($described['embedding']['user_assignment']);
        $this->assertEquals('text-embedding-3-small', $described['embedding']['user_assignment']['model']);
    }

    // ========================================================================
    // US1 Acceptance Scenario 3:
    // New conversation without explicit model uses the inference assignment.
    // ========================================================================

    #[Test]
    public function us1_scenario3_new_conversation_uses_inference_assignment(): void
    {
        $this->scenario = 'us1_conversation_inference';

        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Inference Server',
            'server_url' => 'https://inference.example.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);
        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'name' => 'gpt-4o',
            'server_id' => $server->id,
        ]);

        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Conversation User',
            'email' => 'conversation@example.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);

        // Set user-scoped inference assignment.
        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => $user->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);

        // Simulate starting a new conversation without naming a model.
        $resolved = $this->resolveInferenceForConversation($user->id);
        $this->assertNotNull($resolved, 'Should resolve an inference model for the conversation');
        $this->assertEquals($server->id, $resolved['server_id']);
        $this->assertEquals('gpt-4o', $resolved['model']);

        // Verify: explicit model still wins over inference assignment.
        $otherServer = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Other Server',
            'server_url' => 'https://other.example.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);
        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'name' => 'claude-3',
            'server_id' => $otherServer->id,
        ]);

        $resolvedExplicit = $this->resolveInferenceForConversation(
            $user->id,
            $otherServer->id,
            'claude-3'
        );
        $this->assertNotNull($resolvedExplicit);
        $this->assertEquals($otherServer->id, $resolvedExplicit['server_id']);
        $this->assertEquals('claude-3', $resolvedExplicit['model']);
    }

    // ========================================================================
    // US1 Acceptance Scenario 4:
    // Embedding call uses the assigned embedding model and its server.
    // ========================================================================

    #[Test]
    public function us1_scenario4_embedding_call_uses_assigned_model(): void
    {
        $this->scenario = 'us1_embedding_call_transport';

        // Create a dedicated embedding server (distinct from the fixture's server).
        $embedServer = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Dedicated Embedding Server',
            'server_url' => 'https://embedding-dedicated.example.com/v1/embeddings',
            'provider_type' => 'openai',
        ]);
        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'name' => 'text-embedding-3-small',
            'server_id' => $embedServer->id,
        ]);

        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Embedding Call User',
            'email' => 'embcall@example.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);

        // Assign embedding model at user scope.
        RoleAssignment::create([
            'role' => 'embedding',
            'user_id' => $user->id,
            'server_id' => $embedServer->id,
            'model' => 'text-embedding-3-small',
        ]);

        // Ensure config-level embedding server is NOT set, so the role
        // assignment is the only source of truth.
        config(['llm-client.memory.embedding.server_id' => null]);
        config(['llm-client.memory.embedding.model' => null]);

        // Generate an embedding for this user.
        $embeddingService = $this->app->make(EmbeddingService::class);
        $vector = $embeddingService->generate('Hello, world.', null, $user->id);

        // Verify: we got a valid embedding vector.
        $this->assertIsArray($vector);
        $expectedDim = (int) config('llm-client.memory.embedding.dimension', 1536);
        $this->assertCount($expectedDim, $vector);

        // Verify: the transport captured an embedding request.
        $allPayloads = $this->transport->capturedPayloads();
        $embeddingPayloads = array_values(array_filter(
            $allPayloads,
            fn ($p) => $p->kind === 'embedding'
        ));
        $this->assertNotEmpty($embeddingPayloads, 'Transport should have captured at least one embedding request');

        // Verify: the embedding request used the assigned model.
        $lastEmbedPayload = end($embeddingPayloads);
        $this->assertEquals('text-embedding-3-small', $lastEmbedPayload->model);
    }

    // ========================================================================
    // US1 Acceptance Scenario 5:
    // Clearing an assignment falls back to installation, or unassigned.
    // ========================================================================

    #[Test]
    public function us1_scenario5_clearing_falls_back_to_installation_or_unassigned(): void
    {
        $this->scenario = 'us1_clearing_fallback';

        $service = $this->app->make(RoleAssignmentService::class);
        $resolver = $this->app->make(RoleResolver::class);

        $server = Server::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Fallback Server',
            'server_url' => 'https://fallback.example.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);
        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'name' => 'user-model',
            'server_id' => $server->id,
        ]);
        LanguageModel::create([
            'id' => (string) Str::uuid(),
            'name' => 'install-model',
            'server_id' => $server->id,
        ]);

        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Fallback User',
            'email' => 'fallback@example.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);

        // Set installation default.
        $service->set(ModelRole::Inference, RoleAssignment::INSTALLATION_SCOPE_ID, $server->id, 'install-model');

        // Set user override.
        $service->set(ModelRole::Inference, $user->id, $server->id, 'user-model');

        // Before clear: user gets their override.
        $resolution = $resolver->resolve(ModelRole::Inference, $user->id);
        $this->assertEquals(RoleResolutionStatus::Resolved, $resolution->status);
        $this->assertEquals('user', $resolution->scope);
        $this->assertEquals('user-model', $resolution->model);

        // Clear user override.
        $service->clear(ModelRole::Inference, $user->id);

        // After clear: user falls back to installation default.
        $resolution = $resolver->resolve(ModelRole::Inference, $user->id);
        $this->assertEquals(RoleResolutionStatus::Resolved, $resolution->status);
        $this->assertEquals('installation', $resolution->scope);
        $this->assertEquals('install-model', $resolution->model);

        // Clear installation default too.
        $service->clear(ModelRole::Inference, RoleAssignment::INSTALLATION_SCOPE_ID);

        // Now: role is unassigned at every scope.
        $resolution = $resolver->resolve(ModelRole::Inference, $user->id);
        $this->assertEquals(RoleResolutionStatus::Unassigned, $resolution->status);
        $this->assertFalse($resolution->hasEffectiveModel());
    }

    // ========================================================================
    // US1 Acceptance Scenario 6:
    // Unassigned role shows what breaks.
    // ========================================================================

    #[Test]
    public function us1_scenario6_unassigned_role_shows_what_breaks(): void
    {
        $this->scenario = 'us1_unassigned_what_breaks';

        $service = $this->app->make(RoleAssignmentService::class);
        $resolver = $this->app->make(RoleResolver::class);

        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Unassigned User',
            'email' => 'unassigned@example.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);

        // No assignments at any scope for any role.

        // Resolve all three roles.
        $inference = $resolver->resolve(ModelRole::Inference, $user->id);
        $embedding = $resolver->resolve(ModelRole::Embedding, $user->id);
        $image = $resolver->resolve(ModelRole::Image, $user->id);

        // All three are unassigned.
        $this->assertEquals(RoleResolutionStatus::Unassigned, $inference->status);
        $this->assertEquals(RoleResolutionStatus::Unassigned, $embedding->status);
        $this->assertEquals(RoleResolutionStatus::Unassigned, $image->status);

        // Each role reports what breaks.
        $this->assertNotEmpty(ModelRole::Inference->whatBreaksWhenUnassigned());
        $this->assertNotEmpty(ModelRole::Embedding->whatBreaksWhenUnassigned());
        $this->assertNotEmpty(ModelRole::Image->whatBreaksWhenUnassigned());

        // describeAllRoles reflects the unassigned state.
        $described = $service->describeAllRoles($user->id);
        $this->assertEquals('unassigned', $described['inference']['effective']['status']);
        $this->assertEquals('unassigned', $described['embedding']['effective']['status']);
        $this->assertEquals('unassigned', $described['image']['effective']['status']);
        $this->assertNull($described['inference']['user_assignment']);
        $this->assertNull($described['inference']['installation_assignment']);
        $this->assertNull($described['embedding']['user_assignment']);
        $this->assertNull($described['embedding']['installation_assignment']);

        // Conversation resolution returns null (would be a 422).
        $resolved = $this->resolveInferenceForConversation($user->id);
        $this->assertNull($resolved, 'Should return null when no inference model is assigned');
    }
}
