<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\CodingWorkspaceChange;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 122-workspace-browser-ui, US3, T034 (research.md D5's stated mitigation,
 * mutation checklist row 8). A forged X-Llm-Client-Conversation-Id header
 * naming a conversation this caller does not actually own, or one bound to
 * a different project, must never make it into the change record's
 * attribution -- the header is independently re-verified against
 * Auth::id()-owned data before being trusted, exactly like every other
 * "neither check trusts the other" pairing on this controller. Critically,
 * a failed verification must never block the underlying mutation itself:
 * the write/delete the caller actually requested still succeeds, only the
 * attribution degrades to null.
 */
class WorkspaceChangeHeaderSpoofingTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private Server $server;

    private CodingProject $project;

    private CodingProject $otherProject;

    private string $tmpDir;

    private string $otherTmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        $this->tmpDir = sys_get_temp_dir().'/coding-agent-header-spoof-'.Str::random(12);
        mkdir($this->tmpDir, 0777, true);
        $this->otherTmpDir = sys_get_temp_dir().'/coding-agent-header-spoof-other-'.Str::random(12);
        mkdir($this->otherTmpDir, 0777, true);

        $this->project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'spoofing target project',
            'root_path' => $this->tmpDir,
            'test_command' => null,
            'confirmation_relaxed' => true,
        ]);

        $this->otherProject = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'a different project, same user',
            'root_path' => $this->otherTmpDir,
            'test_command' => null,
            'confirmation_relaxed' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
        if (is_dir($this->otherTmpDir)) {
            $this->removeDirectory($this->otherTmpDir);
        }

        DB::table('coding_workspace_changes')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('coding_projects')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    private function makeAgent(User $user): Agent
    {
        return Agent::create([
            'user_id' => $user->id,
            'name' => 'coding',
        ]);
    }

    private function makeConversation(User $user, Agent $agent, ?CodingProject $project): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Coding conversation',
            'agent_id' => $agent->id,
            'coding_project_id' => $project?->id,
        ]);
    }

    // -----------------------------------------------------------------
    // A header naming a conversation owned by a DIFFERENT user
    // -----------------------------------------------------------------

    #[Test]
    public function a_header_naming_a_conversation_owned_by_a_different_user_is_discarded_but_the_write_still_succeeds(): void
    {
        $foreignAgent = $this->makeAgent($this->otherUser);
        $foreignConversation = $this->makeConversation($this->otherUser, $foreignAgent, $this->project);

        $response = $this->actingAs($this->user, 'api')
            ->withHeaders(['X-Llm-Client-Conversation-Id' => $foreignConversation->id])
            ->postJson($this->apiUrl("coding-project/{$this->project->id}/file"), [
                'path' => 'spoofed-user.txt',
                'content' => 'written despite a spoofed header',
            ]);

        $response->assertStatus(200);
        $this->assertSame('written despite a spoofed header', file_get_contents($this->tmpDir.'/spoofed-user.txt'), 'the mutation itself must never be blocked by a failed header verification');

        $this->assertSame(1, DB::table('coding_workspace_changes')->count());
        $row = CodingWorkspaceChange::first();
        $this->assertNull($row->agent_id, 'a foreign-owned conversation must never attribute the change to its agent');
        $this->assertNull($row->agent_name);
        $this->assertNull($row->conversation_id, 'a foreign-owned conversation id must never be recorded, even as unverified metadata');
    }

    // -----------------------------------------------------------------
    // A header naming a conversation bound to a DIFFERENT project
    // -----------------------------------------------------------------

    #[Test]
    public function a_header_naming_a_conversation_bound_to_a_different_project_is_discarded_but_the_delete_still_succeeds(): void
    {
        file_put_contents($this->tmpDir.'/to-delete.txt', 'still here for now');

        $agent = $this->makeAgent($this->user);
        // Same user, but bound to $this->otherProject, not $this->project --
        // the project-match half of the verification must fail this too.
        $mismatchedConversation = $this->makeConversation($this->user, $agent, $this->otherProject);

        $response = $this->actingAs($this->user, 'api')
            ->withHeaders(['X-Llm-Client-Conversation-Id' => $mismatchedConversation->id])
            ->deleteJson($this->apiUrl("coding-project/{$this->project->id}/file?path=to-delete.txt"));

        $response->assertStatus(200);
        $this->assertFalse(is_file($this->tmpDir.'/to-delete.txt'), 'the delete must still be applied despite the mismatched-project header');

        $this->assertSame(1, DB::table('coding_workspace_changes')->count());
        $row = CodingWorkspaceChange::first();
        $this->assertNull($row->agent_id, 'a conversation bound to a different project must never attribute the change');
        $this->assertNull($row->agent_name);
        $this->assertNull($row->conversation_id);
    }

    // -----------------------------------------------------------------
    // A header naming a conversation that does not exist at all
    // -----------------------------------------------------------------

    #[Test]
    public function a_header_naming_a_nonexistent_conversation_is_discarded_but_the_write_still_succeeds(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->withHeaders(['X-Llm-Client-Conversation-Id' => (string) Str::uuid()])
            ->postJson($this->apiUrl("coding-project/{$this->project->id}/file"), [
                'path' => 'nonexistent-conversation.txt',
                'content' => 'still written',
            ]);

        $response->assertStatus(200);
        $this->assertSame(1, DB::table('coding_workspace_changes')->count());
        $row = CodingWorkspaceChange::first();
        $this->assertNull($row->agent_id);
        $this->assertNull($row->agent_name);
        $this->assertNull($row->conversation_id);
    }
}
