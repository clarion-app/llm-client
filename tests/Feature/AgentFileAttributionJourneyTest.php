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
 * The spec's own Assumptions section ("a change originating from a linked
 * file edit is attributed to the fact of the file's own change... rather
 * than invented as if a product user made it") and quickstart.md step 14/
 * mutation-checklist row 6: a file-sourced version is attributed to the
 * git commit's own author, never to Auth::id() of whichever authenticated
 * user happened to call the linking/sync endpoint.
 *
 * Against a real, throwaway git repository under this test's own tmp
 * directory (never a fixture pointed at this monorepo itself), whose
 * commits are made under one known, deliberately configured author.
 */
class AgentFileAttributionJourneyTest extends TestCase
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
    // Operation catalog seam
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
    // Fixture helpers — a real, throwaway git repository, with commits
    // deliberately made under a *known*, non-caller author
    // ---------------------------------------------------------------

    private function createGitRepo(string $authorName, string $authorEmail): string
    {
        $repoPath = sys_get_temp_dir().'/agent_file_attribution_test_'.uniqid('', true);
        mkdir($repoPath, 0777, true);
        $this->tempRepoPaths[] = $repoPath;

        $this->runGit(['init'], $repoPath);
        $this->runGit(['config', 'user.name', $authorName], $repoPath);
        $this->runGit(['config', 'user.email', $authorEmail], $repoPath);
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
    // Linking imports a file_sync version attributed to the commit
    // author, never to the calling user
    // ---------------------------------------------------------------

    #[Test]
    public function a_linked_versions_attribution_names_the_commit_author_never_the_calling_user(): void
    {
        $repoPath = $this->createGitRepo('Jane Author', 'jane@example.test');
        $this->writeFile($repoPath, 'agent.yaml', "name: file-agent\n");
        $this->commitAll($repoPath, 'Initial agent definition');

        $agentId = $this->createAgent('stored-agent');

        $linkResponse = $this->actingAs($this->user)->putJson($this->agentUrl($agentId).'/link', [
            'repository_path' => $repoPath,
            'file_path' => 'agent.yaml',
        ]);
        $linkResponse->assertStatus(200);

        $version = $this->currentVersionOf($agentId);
        $this->assertNotNull($version);

        $this->assertNull(
            $version->changed_by_user_id,
            'a file-sync version must never attribute a product user (spec Assumptions section)'
        );
        $this->assertNotSame(
            $this->user->id,
            $version->changed_by_user_id,
            'must never be Auth::id() of whichever user called the link endpoint'
        );
        $this->assertSame(
            'Jane Author',
            $version->git_author_name,
            "git_author_name must match the git fixture's own configured commit author exactly"
        );
    }

    // ---------------------------------------------------------------
    // sync-from-file re-attributes each new version to its own commit's
    // author, never to the calling user
    // ---------------------------------------------------------------

    #[Test]
    public function sync_from_file_attributes_the_new_version_to_the_commits_author_not_the_calling_user(): void
    {
        $repoPath = $this->createGitRepo('Jane Author', 'jane@example.test');
        $this->writeFile($repoPath, 'agent.yaml', "name: file-agent\n");
        $this->commitAll($repoPath, 'Initial agent definition');

        $agentId = $this->createAgent('stored-agent');
        $this->actingAs($this->user)->putJson($this->agentUrl($agentId).'/link', [
            'repository_path' => $repoPath,
            'file_path' => 'agent.yaml',
        ])->assertStatus(200);

        // A second, distinct commit under the same configured author.
        $this->writeFile($repoPath, 'agent.yaml', "name: file-agent-v2\n");
        $this->commitAll($repoPath, 'Second agent definition');

        $syncResponse = $this->actingAs($this->user)->postJson($this->agentUrl($agentId).'/sync-from-file');
        $syncResponse->assertStatus(200);

        $version = $this->currentVersionOf($agentId);
        $this->assertNotNull($version);

        $this->assertSame('file_sync', $version->source);
        $this->assertNull($version->changed_by_user_id, 'sync-from-file must never attribute a product user either');
        $this->assertSame('Jane Author', $version->git_author_name);
        $this->assertNotSame($this->user->id, $version->changed_by_user_id);
    }
}
