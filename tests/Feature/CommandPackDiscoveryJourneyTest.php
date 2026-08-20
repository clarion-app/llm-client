<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\McpSession;
use ClarionApp\LlmClient\Services\CommandPackLoader;
use ClarionApp\LlmClient\Services\McpPromptRegistry;
use ClarionApp\LlmClient\Services\McpProtocolHandler;
use ClarionApp\LlmClient\Services\McpSessionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * 127-command-packs, Phase 3 (US1), T014 (tasks.md; quickstart.md Scenario
 * 1, all four steps, end-to-end through McpProtocolHandler::dispatch(), no
 * live transport).
 *
 * Mirrors McpProtocolHandlerPromptsResourcesTest.php's own real-McpSession +
 * mocked-McpSessionManager + JSON-RPC-payload convention -- but, unlike that
 * file, does NOT mock McpPromptRegistry: this journey is the wiring test for
 * the real registry/loader/parser/builder chain reached through the actual
 * dispatch() surface a real MCP client would exercise, plus a real
 * temp-directory-backed CodingProject fixture (persisted, per Grounding
 * note 8's convention, since McpPromptRegistry's own ownership check needs
 * a real row to find).
 *
 * Every case below is expected to fail against the current tree: the
 * 'prompts/list'/'prompts/get' switch cases in McpProtocolHandler::dispatch()
 * do not yet pass $session through to their handlers, and those handlers do
 * not yet read `_codingProjectId` at all -- this is the correct "genuinely
 * red" state for Phase 3 (T013/T014 precede T015/T016's implementation).
 */
class CommandPackDiscoveryJourneyTest extends TestCase
{
    private McpProtocolHandler $handler;

    private string $userId;

    private string $sessionId;

    private McpSession $mockSession;

    /** @var list<string> temp directories created by this test, removed in tearDown */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = (string) Str::uuid();
        $this->sessionId = (string) Str::uuid();

        // Real (but unsaved) McpSession model, matching
        // McpProtocolHandlerPromptsResourcesTest.php's own convention.
        $this->mockSession = new McpSession();
        $this->mockSession->id = $this->sessionId;
        $this->mockSession->user_id = $this->userId;

        $mockSessionManager = Mockery::mock(McpSessionManager::class);
        $mockSessionManager->shouldReceive('validateSession')
            ->with($this->sessionId, $this->userId)
            ->andReturn($this->mockSession);
        $mockSessionManager->shouldReceive('touchSession')
            ->with($this->sessionId)
            ->andReturn(true);

        $this->handler = new McpProtocolHandler($mockSessionManager);

        $this->mockPackages([
            '@clarion-app/wizlights' => [
                'listOperations' => 'To adjust the lighting...',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }

        restore_error_handler();
        restore_exception_handler();
        Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function mockPackages(array $packagePrompts): void
    {
        $descriptions = [];
        foreach (array_keys($packagePrompts) as $pkg) {
            $descriptions[$pkg] = ['description' => "Description for {$pkg}"];
        }

        $reflection = new \ReflectionClass(ClarionPackageServiceProvider::class);

        $descProp = $reflection->getProperty('packageDescriptions');
        $descProp->setAccessible(true);
        $descProp->setValue(null, $descriptions);

        $promptsProp = $reflection->getProperty('customPrompts');
        $promptsProp->setAccessible(true);
        $promptsProp->setValue(null, $packagePrompts);
    }

    private function makeProjectDir(): string
    {
        $dir = sys_get_temp_dir().'/command_pack_journey_'.uniqid('', true);
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;

        return $dir;
    }

    private function write(string $projectDir, string $relativePath, string $content): void
    {
        $full = $projectDir.'/'.$relativePath;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $content);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * 127-command-packs, Phase 4 (US2), T019: extracts the five real,
     * unmodified Spec-Kit 0.8.10 workflow command templates
     * (templates/commands/{specify,clarify,plan,tasks,implement}.md) byte-
     * for-byte out of the cached release zip and places them at
     * .claude/commands/speckit.<name>.md inside $projectDir -- this
     * "speckit." prefix/location mirrors this repository's own convention
     * (Grounding note 12), not spec-kit 0.8.10's current literal
     * `specify init --ai claude` output shape (which now writes
     * .claude/skills/ instead; that gap is explicitly out of scope for this
     * feature, research.md D1).
     *
     * Reads directly from the zip via ZipArchive rather than pasting the
     * (multi-KB, real-world) file contents into this test's source, so the
     * fixture is guaranteed byte-identical to the cached release artifact
     * with no transcription step in between.
     *
     * @return list<string> absolute paths of the five fixture files written
     */
    private function installSpecKitFixture(string $projectDir): array
    {
        $zipPath = '/home/tim/Downloads/spec-kit-0.8.10.zip';

        $this->assertFileExists(
            $zipPath,
            'the cached spec-kit-0.8.10.zip fixture source is required for this test and was not found'
        );

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);
        $this->assertTrue($opened === true, "failed to open {$zipPath} as a zip archive");

        $names = ['specify', 'clarify', 'plan', 'tasks', 'implement'];
        $written = [];

        foreach ($names as $name) {
            $entryPath = "spec-kit-0.8.10/templates/commands/{$name}.md";
            $content = $zip->getFromName($entryPath);

            $this->assertNotFalse($content, "could not extract {$entryPath} from {$zipPath}");

            $destination = $projectDir."/.claude/commands/speckit.{$name}.md";
            @mkdir(dirname($destination), 0777, true);
            file_put_contents($destination, $content);

            $written[] = $destination;
        }

        $zip->close();

        return $written;
    }

    private function makeProject(string $rootPath): CodingProject
    {
        return CodingProject::create([
            'user_id' => $this->userId,
            'name' => 'journey project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    private function makeJsonRpcRequest(string $method, array $params = [], ?int $id = null, ?string $sessionId = null): Request
    {
        $body = ['jsonrpc' => '2.0', 'method' => $method];
        if ($id !== null) {
            $body['id'] = $id;
        }
        if (!empty($params)) {
            $body['params'] = $params;
        }

        $headers = ['CONTENT_TYPE' => 'application/json'];

        $request = Request::create('/api/mcp', 'POST', [], [], [], $headers, json_encode($body));

        if ($sessionId) {
            $request->headers->set('Mcp-Session-Id', $sessionId);
        }

        return $request;
    }

    // -----------------------------------------------------------------
    // prompts/list, real dispatch, with _codingProjectId in params
    // -----------------------------------------------------------------

    #[Test]
    public function a_real_prompts_list_request_with_coding_project_id_includes_the_project_command_alongside_builtins(): void
    {
        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/summarize.md', <<<'MD'
---
description: Summarize the given input.
---
Please summarize the following: $ARGUMENTS
MD);
        $project = $this->makeProject($projectDir);

        $request = $this->makeJsonRpcRequest('prompts/list', [
            '_codingProjectId' => $project->id,
        ], 10, $this->sessionId);

        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('prompts', $result['result']);

        $names = array_map(fn ($p) => $p['name'], $result['result']['prompts']);
        $this->assertContains('summarize', $names, 'the project-defined command must appear in the listing');
        $this->assertContains('wizlights_listOperations', $names, 'built-ins must still be present alongside it');
    }

    // -----------------------------------------------------------------
    // prompts/get, real dispatch, for the project command with
    // arguments.command set
    // -----------------------------------------------------------------

    #[Test]
    public function a_real_prompts_get_request_for_the_project_command_returns_a_delimited_argument_block(): void
    {
        $projectDir = $this->makeProjectDir();
        $this->write($projectDir, '.claude/commands/summarize.md', <<<'MD'
---
description: Summarize the given input.
---
Please summarize the following: $ARGUMENTS
MD);
        $project = $this->makeProject($projectDir);

        $request = $this->makeJsonRpcRequest('prompts/get', [
            'name' => 'summarize',
            'arguments' => ['command' => 'Add dark mode to the settings screen'],
            '_codingProjectId' => $project->id,
        ], 11, $this->sessionId);

        $result = $this->handler->dispatch($request, $this->userId);

        $this->assertArrayHasKey('result', $result);
        $text = $result['result']['messages'][0]['content']['text'];

        $this->assertStringContainsString('--- BEGIN ARGUMENT TEXT ---', $text);
        $this->assertStringContainsString('Add dark mode to the settings screen', $text);
        $this->assertStringContainsString('--- END ARGUMENT TEXT ---', $text);
        $this->assertStringContainsString('Please summarize the following:', $text);
    }

    // -----------------------------------------------------------------
    // 127-command-packs, Phase 4 (US2), T020: an unmodified Spec-Kit
    // 0.8.10-initialized layout is discoverable and invocable with zero
    // conversion, zero side effects, and zero Spec-Kit-specific code path
    // (quickstart.md Scenario 2).
    // -----------------------------------------------------------------

    #[Test]
    public function an_unmodified_spec_kit_layout_is_discoverable_and_invocable_with_no_side_effects_and_no_special_casing(): void
    {
        $projectDir = $this->makeProjectDir();
        $fixtureFiles = $this->installSpecKitFixture($projectDir);
        $project = $this->makeProject($projectDir);

        $checksumsBefore = array_map(fn (string $path) => md5_file($path), $fixtureFiles);

        // --- AS1 (SC-001): all five discovered, correctly named, no problems.
        $loader = new CommandPackLoader();
        $result = $loader->discover($project);

        $names = array_map(fn ($template) => $template->name, $result->commands);
        sort($names);

        $this->assertSame(
            ['speckit.clarify', 'speckit.implement', 'speckit.plan', 'speckit.specify', 'speckit.tasks'],
            $names,
            'all five real Spec-Kit command files must be discovered under their speckit.<name> names, and only those five'
        );
        $this->assertSame(
            [],
            $result->problems,
            'the real handoffs:/scripts: frontmatter keys must not cause any parse failure'
        );

        // --- "no file in the workspace altered as a precondition" (AS1):
        // byte-identical before/after discover().
        $checksumsAfter = array_map(fn (string $path) => md5_file($path), $fixtureFiles);
        $this->assertSame(
            $checksumsBefore,
            $checksumsAfter,
            'discover() must never write to, move, or reformat any fixture file'
        );

        // --- AS2: invocation resolves through the exact same code path
        // Scenario 1's summarize command used -- no Spec-Kit-specific
        // branch anywhere in production code.
        $registry = new McpPromptRegistry();
        $response = $registry->getPrompt(
            'speckit.plan',
            ['command' => 'Plan the auth feature'],
            codingProjectId: $project->id,
            userId: $this->userId,
        );

        $this->assertNotNull($response, 'speckit.plan must resolve exactly like any other project-defined command');
        $text = $response['messages'][0]['content']['text'];

        $this->assertStringContainsString('--- BEGIN ARGUMENT TEXT ---', $text);
        $this->assertStringContainsString('Plan the auth feature', $text);
        $this->assertStringContainsString('--- END ARGUMENT TEXT ---', $text);
        $this->assertStringContainsString('You **MUST** consider the user input before proceeding', $text);
    }
}
