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
 * Phase 6/Polish reconciliation (T055): contracts §8/§11 both document a
 * distinct 422 `file_unreadable` response (PUT .../link, POST
 * .../sync-from-file) and the same content-validation 422 posture as
 * every other write endpoint — neither was exercised at the HTTP layer by
 * any Phase 5 test (GitDefinitionFileReaderTest only proves
 * AgentFileUnreadableException is thrown at the unit level;
 * AgentFileDivergenceJourneyTest/AgentFileAttributionJourneyTest only ever
 * exercise the happy path). This file closes that gap — found during the
 * final FR/SC reconciliation, not a functional defect (the controller code
 * already handles both paths correctly; this only adds the missing
 * coverage proving it).
 */
class AgentLinkAndSyncErrorPathsTest extends TestCase
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

    private function base(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function agentUrl(string $id): string
    {
        return $this->base().'/'.$id;
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

    private function createAgent(string $name): string
    {
        return $this->actingAs($this->user)
            ->postJson($this->base(), ['definition' => "name: {$name}"])
            ->assertStatus(201)
            ->json('id');
    }

    private function createGitRepo(): string
    {
        $repoPath = sys_get_temp_dir().'/agent_link_error_paths_test_'.uniqid('', true);
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

    // ---------------------------------------------------------------
    // PUT /agents/{id}/link — file_unreadable (contracts §8)
    // ---------------------------------------------------------------

    #[Test]
    public function linking_to_a_nonexistent_file_returns_422_file_unreadable_and_changes_nothing(): void
    {
        $repoPath = $this->createGitRepo();
        $agentId = $this->createAgent('stored-agent');
        $versionCountBefore = AgentVersion::where('agent_id', $agentId)->count();

        $response = $this->actingAs($this->user)->putJson($this->agentUrl($agentId).'/link', [
            'repository_path' => $repoPath,
            'file_path' => 'does-not-exist.yaml',
        ]);

        $response->assertStatus(422);
        $this->assertSame('file_unreadable', $response->json('error'));

        $this->assertSame(
            $versionCountBefore,
            AgentVersion::where('agent_id', $agentId)->count(),
            'a failed link() must write no new version row',
        );
        $this->assertNull(
            DB::table('agents')->where('id', $agentId)->value('linked_repository_path'),
            'a failed link() must leave the agent unlinked',
        );
    }

    // ---------------------------------------------------------------
    // PUT /agents/{id}/link — content-validation failure (contracts §8's
    // "422 on failure, nothing changed" posture, same as §1/§4)
    // ---------------------------------------------------------------

    #[Test]
    public function linking_to_a_file_with_unresolvable_content_returns_422_and_leaves_the_agent_unlinked(): void
    {
        $repoPath = $this->createGitRepo();
        $this->writeFile($repoPath, 'agent.yaml', "name: broken-agent\ncapabilities: [web_browsing]\n");
        $this->commitAll($repoPath, 'Broken agent definition');

        $agentId = $this->createAgent('stored-agent');
        $versionCountBefore = AgentVersion::where('agent_id', $agentId)->count();

        $response = $this->actingAs($this->user)->putJson($this->agentUrl($agentId).'/link', [
            'repository_path' => $repoPath,
            'file_path' => 'agent.yaml',
        ]);

        $response->assertStatus(422);
        $this->assertSame('UnknownCapability', $response->json('kind'));

        $this->assertSame(
            $versionCountBefore,
            AgentVersion::where('agent_id', $agentId)->count(),
            'a failed link() must write no new version row',
        );
        $this->assertNull(
            DB::table('agents')->where('id', $agentId)->value('linked_repository_path'),
            'a failed link() must leave the agent unlinked',
        );
    }

    // ---------------------------------------------------------------
    // POST /agents/{id}/sync-from-file — not_linked (contracts §11)
    // ---------------------------------------------------------------

    #[Test]
    public function syncing_an_unlinked_agent_returns_422_not_linked(): void
    {
        $agentId = $this->createAgent('unlinked-agent');

        $response = $this->actingAs($this->user)->postJson($this->agentUrl($agentId).'/sync-from-file');

        $response->assertStatus(422);
        $this->assertSame('not_linked', $response->json('error'));
    }

    // ---------------------------------------------------------------
    // POST /agents/{id}/sync-from-file — file_unreadable after the file
    // that was linked has since disappeared (contracts §11)
    // ---------------------------------------------------------------

    #[Test]
    public function syncing_after_the_linked_file_disappears_returns_422_file_unreadable_and_the_agent_stays_on_its_last_synced_version(): void
    {
        $repoPath = $this->createGitRepo();
        $this->writeFile($repoPath, 'agent.yaml', "name: file-agent\n");
        $this->commitAll($repoPath, 'Initial agent definition');

        $agentId = $this->createAgent('stored-agent');

        $this->actingAs($this->user)->putJson($this->agentUrl($agentId).'/link', [
            'repository_path' => $repoPath,
            'file_path' => 'agent.yaml',
        ])->assertStatus(200);

        $currentVersionIdBefore = DB::table('agents')->where('id', $agentId)->value('current_version_id');
        $versionCountBefore = AgentVersion::where('agent_id', $agentId)->count();

        unlink($repoPath.'/agent.yaml');

        $response = $this->actingAs($this->user)->postJson($this->agentUrl($agentId).'/sync-from-file');

        $response->assertStatus(422);
        $this->assertSame('file_unreadable', $response->json('error'));

        $this->assertSame(
            $currentVersionIdBefore,
            DB::table('agents')->where('id', $agentId)->value('current_version_id'),
            'a failed sync-from-file must leave current_version_id unchanged',
        );
        $this->assertSame(
            $versionCountBefore,
            AgentVersion::where('agent_id', $agentId)->count(),
            'a failed sync-from-file must write no new version row',
        );
    }
}
