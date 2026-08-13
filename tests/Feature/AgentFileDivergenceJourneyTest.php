<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\AgentVersion;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * spec.md US3 Acceptance Scenarios 1-3, the Edge Cases section's
 * "outside-the-product edit," and quickstart.md steps 9-13/mutation-
 * checklist row 8, through the real HTTP endpoints (contracts §8/§9/§10/
 * §11) — against a real, throwaway git repository created under this
 * test's own tmp directory (never a fixture pointed at this monorepo
 * itself, quickstart.md's own explicit instruction).
 */
class AgentFileDivergenceJourneyTest extends TestCase
{
    private User $user;

    /** @var string[] */
    private array $tempRepoPaths = [];

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

        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        foreach ($this->tempRepoPaths as $path) {
            $this->removeDirectory($path);
        }
        $this->tempRepoPaths = [];

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // URL helpers
    // ---------------------------------------------------------------

    private function base(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function agentUrl(string $id): string
    {
        return $this->base().'/'.$id;
    }

    // ---------------------------------------------------------------
    // Operation catalog seam — required before any *valid*
    // AgentDefinitionParser::parse() call (AgentServiceTest's own
    // established convention).
    // ---------------------------------------------------------------

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

    private function createAgent(string $name): string
    {
        return $this->actingAs($this->user)
            ->postJson($this->base(), ['definition' => "name: {$name}"])
            ->assertStatus(201)
            ->json('id');
    }

    // ---------------------------------------------------------------
    // Fixture helpers — a real, throwaway git repository per test
    // ---------------------------------------------------------------

    private function createGitRepo(): string
    {
        $repoPath = sys_get_temp_dir().'/agent_file_divergence_test_'.uniqid('', true);
        mkdir($repoPath, 0777, true);
        $this->tempRepoPaths[] = $repoPath;

        $this->runGit(['init'], $repoPath);
        $this->runGit(['config', 'user.name', 'Test Author'], $repoPath);
        $this->runGit(['config', 'user.email', 'test-author@example.test'], $repoPath);
        $this->runGit(['config', 'commit.gpgsign', 'false'], $repoPath);

        return $repoPath;
    }

    private function runGit(array $args, string $cwd): void
    {
        (new Process(array_merge(['git'], $args), $cwd))->mustRun();
    }

    private function writeFile(string $repoPath, string $relPath, string $content): void
    {
        file_put_contents($repoPath.'/'.$relPath, $content);
    }

    private function commitAll(string $repoPath, string $message): void
    {
        $this->runGit(['add', '.'], $repoPath);
        $this->runGit(['commit', '-m', $message], $repoPath);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (new \FilesystemIterator($path) as $item) {
            if ($item->isDir()) {
                $this->removeDirectory($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    private function currentVersionOf(string $agentId): ?AgentVersion
    {
        $currentVersionId = DB::table('agents')->where('id', $agentId)->value('current_version_id');

        return $currentVersionId === null ? null : AgentVersion::find($currentVersionId);
    }

    // ---------------------------------------------------------------
    // Step 9 — linking starts in-step (US3 AC1)
    // ---------------------------------------------------------------

    #[Test]
    public function linking_starts_in_step_and_imports_the_files_content_immediately(): void
    {
        $repoPath = $this->createGitRepo();
        $this->writeFile($repoPath, 'agent.yaml', "name: file-agent\n");
        $this->commitAll($repoPath, 'Initial agent definition');

        $agentId = $this->createAgent('stored-agent');

        $linkResponse = $this->actingAs($this->user)->putJson($this->agentUrl($agentId).'/link', [
            'repository_path' => $repoPath,
            'file_path' => 'agent.yaml',
        ]);

        $linkResponse->assertStatus(200);
        $this->assertSame('in_step', $linkResponse->json('divergence.state'));

        $currentVersion = $this->currentVersionOf($agentId);
        $this->assertNotNull($currentVersion);
        $this->assertSame(
            "name: file-agent\n",
            $currentVersion->raw_definition,
            "linking must import the file's content immediately, not the agent's pre-link content"
        );
        $this->assertSame('file_sync', $currentVersion->source);
    }

    // ---------------------------------------------------------------
    // Step 10 / mutation row 8 — file ahead (US3 AC2)
    // ---------------------------------------------------------------

    #[Test]
    public function file_edited_on_disk_without_updating_the_stored_agent_reports_file_ahead(): void
    {
        $repoPath = $this->createGitRepo();
        $this->writeFile($repoPath, 'agent.yaml', "name: file-agent\n");
        $this->commitAll($repoPath, 'Initial agent definition');

        $agentId = $this->createAgent('stored-agent');
        $this->actingAs($this->user)->putJson($this->agentUrl($agentId).'/link', [
            'repository_path' => $repoPath,
            'file_path' => 'agent.yaml',
        ])->assertStatus(200);

        // No commit needed — a working-tree edit alone is enough
        // (research.md D11).
        $this->writeFile($repoPath, 'agent.yaml', "name: file-agent-edited\n");

        $divergenceResponse = $this->actingAs($this->user)->getJson($this->agentUrl($agentId).'/divergence');

        $divergenceResponse->assertStatus(200);
        $this->assertSame('file_ahead', $divergenceResponse->json('state'));
        $this->assertSame('stored_agent', $divergenceResponse->json('governs'));
    }

    // ---------------------------------------------------------------
    // Step 11 / mutation row 7 — stored ahead (US3 AC2)
    // ---------------------------------------------------------------

    #[Test]
    public function stored_agent_edited_without_updating_the_file_reports_stored_ahead(): void
    {
        $repoPath = $this->createGitRepo();
        $this->writeFile($repoPath, 'agent.yaml', "name: file-agent\n");
        $this->commitAll($repoPath, 'Initial agent definition');

        $agentId = $this->createAgent('stored-agent');
        $this->actingAs($this->user)->putJson($this->agentUrl($agentId).'/link', [
            'repository_path' => $repoPath,
            'file_path' => 'agent.yaml',
        ])->assertStatus(200);

        // File untouched; the stored agent changes via the product's own
        // edit path.
        $this->actingAs($this->user)->putJson($this->agentUrl($agentId), [
            'definition' => 'name: stored-agent-v2',
        ])->assertStatus(200);

        $divergenceResponse = $this->actingAs($this->user)->getJson($this->agentUrl($agentId).'/divergence');

        $divergenceResponse->assertStatus(200);
        $this->assertSame('stored_ahead', $divergenceResponse->json('state'));
    }

    // ---------------------------------------------------------------
    // Step 12 — divergence clears after an explicit sync (US3 AC3)
    // ---------------------------------------------------------------

    #[Test]
    public function divergence_clears_after_an_explicit_sync_from_file(): void
    {
        $repoPath = $this->createGitRepo();
        $this->writeFile($repoPath, 'agent.yaml', "name: file-agent\n");
        $this->commitAll($repoPath, 'Initial agent definition');

        $agentId = $this->createAgent('stored-agent');
        $this->actingAs($this->user)->putJson($this->agentUrl($agentId).'/link', [
            'repository_path' => $repoPath,
            'file_path' => 'agent.yaml',
        ])->assertStatus(200);

        $this->writeFile($repoPath, 'agent.yaml', "name: file-agent-edited\n");
        $this->actingAs($this->user)->getJson($this->agentUrl($agentId).'/divergence')
            ->assertStatus(200)
            ->assertJsonPath('state', 'file_ahead');

        $versionCountBefore = DB::table('agent_versions')->where('agent_id', $agentId)->count();

        $syncResponse = $this->actingAs($this->user)->postJson($this->agentUrl($agentId).'/sync-from-file');
        $syncResponse->assertStatus(200);

        $this->assertSame(
            $versionCountBefore + 1,
            DB::table('agent_versions')->where('agent_id', $agentId)->count(),
            'sync-from-file must insert exactly one new version'
        );

        $newVersion = $this->currentVersionOf($agentId);
        $this->assertNotNull($newVersion);
        $this->assertSame('file_sync', $newVersion->source);
        $this->assertSame("name: file-agent-edited\n", $newVersion->raw_definition);

        $this->actingAs($this->user)->getJson($this->agentUrl($agentId).'/divergence')
            ->assertStatus(200)
            ->assertJsonPath('state', 'in_step');
    }

    // ---------------------------------------------------------------
    // Step 13 — an outside-the-product edit is never silently applied or
    // silently overwritten (Edge Cases)
    // ---------------------------------------------------------------

    #[Test]
    public function an_outside_the_product_file_edit_is_never_silently_applied_or_overwritten(): void
    {
        $repoPath = $this->createGitRepo();
        $this->writeFile($repoPath, 'agent.yaml', "name: file-agent\n");
        $this->commitAll($repoPath, 'Initial agent definition');

        $agentId = $this->createAgent('stored-agent');
        $this->actingAs($this->user)->putJson($this->agentUrl($agentId).'/link', [
            'repository_path' => $repoPath,
            'file_path' => 'agent.yaml',
        ])->assertStatus(200);

        // An outside-the-product edit: uncommitted, and unrelated to what
        // the product is about to PUT next.
        $this->writeFile($repoPath, 'agent.yaml', "name: file-agent-outside-edit\n");

        // A genuine, independent PUT through the product — both sides now
        // moved independently from the last synced baseline.
        $this->actingAs($this->user)->putJson($this->agentUrl($agentId), [
            'definition' => 'name: stored-agent-third',
        ])->assertStatus(200);

        $divergenceResponse = $this->actingAs($this->user)->getJson($this->agentUrl($agentId).'/divergence');
        $divergenceResponse->assertStatus(200);
        $this->assertSame('both_changed', $divergenceResponse->json('state'));

        // Neither side was touched by the other.
        $this->assertSame(
            "name: file-agent-outside-edit\n",
            file_get_contents($repoPath.'/agent.yaml'),
            'the file on disk must still hold its own outside-the-product edit — nothing auto-exported to it'
        );

        $currentVersion = $this->currentVersionOf($agentId);
        $this->assertNotNull($currentVersion);
        $this->assertSame(
            'name: stored-agent-third',
            $currentVersion->raw_definition,
            "the stored agent's new current version must hold exactly what was PUT"
        );
        $this->assertSame(
            'product_edit',
            $currentVersion->source,
            'nothing was auto-imported from the file — this version must come from the PUT, not a file_sync'
        );
    }

    // ---------------------------------------------------------------
    // DELETE /agents/{id}/link (§9)
    // ---------------------------------------------------------------

    #[Test]
    public function unlinking_clears_the_link_without_touching_any_prior_file_sync_sourced_version(): void
    {
        $repoPath = $this->createGitRepo();
        $this->writeFile($repoPath, 'agent.yaml', "name: file-agent\n");
        $this->commitAll($repoPath, 'Initial agent definition');

        $agentId = $this->createAgent('stored-agent');
        $this->actingAs($this->user)->putJson($this->agentUrl($agentId).'/link', [
            'repository_path' => $repoPath,
            'file_path' => 'agent.yaml',
        ])->assertStatus(200);

        $linkedVersion = $this->currentVersionOf($agentId);
        $this->assertNotNull($linkedVersion);
        $linkedVersionBefore = $linkedVersion->toArray();

        $unlinkResponse = $this->actingAs($this->user)->deleteJson($this->agentUrl($agentId).'/link');

        $unlinkResponse->assertStatus(200);
        $this->assertFalse($unlinkResponse->json('linked'));

        $this->assertNull(DB::table('agents')->where('id', $agentId)->value('linked_repository_path'));
        $this->assertNull(DB::table('agents')->where('id', $agentId)->value('linked_file_path'));
        $this->assertNull(DB::table('agents')->where('id', $agentId)->value('linked_synced_file_hash'));

        $linkedVersionAfter = $linkedVersion->fresh()->toArray();
        $this->assertSame(
            $linkedVersionBefore,
            $linkedVersionAfter,
            'unlinking must never rewrite a prior file_sync-sourced version row — history is never rewritten by unlinking'
        );
    }
}
