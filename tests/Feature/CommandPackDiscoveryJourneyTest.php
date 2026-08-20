<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\McpSession;
use ClarionApp\LlmClient\Services\McpProtocolHandler;
use ClarionApp\LlmClient\Services\McpSessionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

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
}
