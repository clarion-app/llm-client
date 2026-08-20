<?php

namespace Tests\RealSpecKit;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Controllers\CodingWorkspaceController;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\CodingAgentProvisioner;
use ClarionApp\LlmClient\Services\McpPromptRegistry;
use Dedoc\Scramble\Generator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Integration\AssembledSystemTestCase;
use Tests\RealSpecKit\Support\EnvironmentUnavailableException;
use Tests\RealSpecKit\Support\SpecKitCliFixtureBuilder;
use Tests\RealSpecKit\Support\SpecKitFixtureProject;
use Tests\RealSpecKit\Support\SpecKitOutcomeLedger;

/**
 * 129-speckit-verify-acceptance, Phase 4 (US2), T019 — quickstart.md
 * Scenario 2 in full: invoking a real, unmodified `speckit.plan` command
 * with argument text runs the agent on the command's real instructions
 * (the argument landing inside CommandArgumentPromptBuilder's delimited
 * block, the rest of the real .agent.md body present verbatim), and the
 * file that command's real instructions direct it to produce
 * (specs/001-verify-fixture/plan.md, via the real, Docker-executed
 * `.specify/scripts/bash/setup-plan.sh --json`) actually appears on the
 * real fixture filesystem afterward (FR-004/FR-005).
 *
 * Composes AssembledSystemTestCase's scripted-model harness (research.md
 * D4) with CommandExecutionConfirmationJourneyTest's real-coding-agent
 * wiring (seedOperationCatalog(), fakeCodingWorkspaceHttp()) against a
 * real specify-init fixture (SpecKitCliFixtureBuilder, Phase 3's own
 * convention) — nothing about file access, template parsing, or script
 * execution is faked; only the LLM's responses are scripted.
 */
#[Group('real-speckit-cli')]
class CommandArgumentInvocationJourneyTest extends AssembledSystemTestCase
{
    private const PLAN_CONTENT = "# Widget Feature Plan\n\nConcrete plan content written by the scripted agent run.\n";

    private const EXPECTED_RELATIVE_PLAN_PATH = 'specs/001-verify-fixture/plan.md';

    private ?SpecKitFixtureProject $speckitFixture = null;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            SpecKitCliFixtureBuilder::assertAvailable();
        } catch (EnvironmentUnavailableException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        // setup-plan.sh's shebang is #!/usr/bin/env bash -- the package's
        // default sandbox image (alpine:latest) has no bash, only
        // busybox ash. This overrides the same config key
        // LanguageAvailabilityProbeTest/LanguageExecutionTest already
        // override, to an image already pulled in this environment that
        // does carry bash (and, incidentally, git -- though the newer
        // CLI's feature.json precondition means the script never needs
        // it, per research.md D5).
        config(['llm-client.coding_agent.command_image' => 'nikolaik/python-nodejs:latest']);
    }

    protected function tearDown(): void
    {
        if ($this->speckitFixture !== null && is_dir($this->speckitFixture->rootPath)) {
            (new Process(['rm', '-rf', $this->speckitFixture->rootPath]))->run();
        }

        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function invoking_speckit_plan_with_argument_text_actually_runs_it(): void
    {
        $this->scenario = 'command_argument_invocation';
        $this->entryPath = 'sync';

        // --- Step 2: a real, fresh specify init fixture, plus the one
        // small precondition file the real CLI itself never writes
        // (research.md D5 -- confirmed live to be sufficient, and not a
        // hand-adjustment of anything the CLI produced).
        $this->speckitFixture = (new SpecKitCliFixtureBuilder())->build('copilot', '--commands');

        file_put_contents(
            $this->speckitFixture->rootPath.'/.specify/feature.json',
            json_encode(['feature_directory' => 'specs/001-verify-fixture'])
        );

        // --- Step 3: fixture conversation + real coding-agent wiring.
        $conversationFixture = $this->fixture()->build();

        // CodingWorkspaceController::runCommand()/writeFile() both read
        // Auth::id() for attribution -- fakeCodingWorkspaceHttp() below
        // dispatches straight into the real controller in-process, so
        // this must be set exactly like
        // CommandExecutionConfirmationJourneyTest::setUp() does.
        $this->actingAs($conversationFixture->user, 'api');

        $this->seedOperationCatalog();
        $agent = app(CodingAgentProvisioner::class)->ensureForUser($conversationFixture->user->id);

        $project = CodingProject::create([
            'user_id' => $conversationFixture->user->id,
            'name' => 'speckit invocation journey',
            'root_path' => $this->speckitFixture->rootPath,
            'test_command' => null,
            'confirmation_relaxed' => true,
        ]);

        $conversationFixture->conversation->update([
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
            'coding_project_id' => $project->id,
        ]);

        // --- Step 4: internal MCP tool-call HTTP reaches the real controller.
        $this->fakeCodingWorkspaceHttp();

        // McpToolExecutor's default tokenFactory calls $user->createToken()
        // (Laravel Passport), which needs a fully-provisioned OAuth client
        // this environment never sets up for tests -- every other test
        // that drives McpToolExecutor through a real execute_operation
        // call swaps this same closure for a plain string, mirroring
        // CommandExecutionConfirmationJourneyTest::service()'s identical
        // substitution.
        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolExecutor::class, new \ClarionApp\LlmClient\Services\McpToolExecutor(
            $this->app->make(\ClarionApp\LlmClient\Services\McpToolRegistry::class),
            null,
            fn ($user) => 'test-token',
        ));

        // --- Step 5 (AS1/FR-004): the argument text lands inside the
        // exact delimited block, and the rest of the real, unmodified
        // speckit.plan.agent.md body -- including its helper-script
        // reference -- is present verbatim, not summarized.
        $prompt = (new McpPromptRegistry())->getPrompt(
            'speckit.plan',
            ['command' => 'Plan the widget feature'],
            codingProjectId: $project->id,
            userId: $conversationFixture->user->id,
        );

        $this->assertNotNull($prompt, 'speckit.plan must resolve against the real fixture project');
        $promptText = $prompt['messages'][0]['content']['text'];

        $this->assertStringContainsString('--- BEGIN ARGUMENT TEXT ---', $promptText);
        $this->assertStringContainsString('Plan the widget feature', $promptText);
        $this->assertStringContainsString('--- END ARGUMENT TEXT ---', $promptText);
        $this->assertStringContainsString(
            'setup-plan.sh',
            $promptText,
            'the real, unmodified speckit.plan instructions must genuinely reference the helper script it runs'
        );

        // --- Step 6: script the invocation.
        //
        // Wiring surprise (documented for the completion report, not a
        // test bug): AgentLoopService::run()'s real loop -- not a mock,
        // not this harness -- auto-stops after ANY iteration whose tool
        // calls were all execute_operation AND all succeeded
        // (allExecuteOperationsSucceeded(), AgentLoopService.php ~L1941),
        // for an interactive (non-unattended) run. A first scripted turn
        // containing only the runCommand call already satisfies that
        // condition and ends the run at status=completed before a second
        // LLM round-trip is ever requested -- confirmed live: an earlier
        // version of this test scripted runCommand then writeFile as two
        // separate turns (quickstart.md Scenario 2 step 3's literal
        // phrasing) and the writeFile turn was never dispatched at all
        // (0 additional captured payloads, the file left holding
        // setup-plan.sh's own template copy). Both real tool calls are
        // therefore issued together as two tool_calls in the SAME
        // scripted assistant turn -- exactly the shape a model that
        // already knows (from this very prompt text, per research.md D5)
        // that the feature directory is 'specs/001-verify-fixture' would
        // produce without needing to see the first call's result before
        // choosing the second's path. No third, separate "final answer"
        // turn exists to script: the real auto-stop shortcut is itself
        // the normal turn boundary this run reaches.
        $this->script()->steps[] = [
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'tool_calls' => [
                            [
                                'id' => 'call_'.bin2hex(random_bytes(8)),
                                'type' => 'function',
                                'function' => [
                                    'name' => 'execute_operation',
                                    'arguments' => json_encode([
                                        'operationId' => AgentLoopService::CODING_WORKSPACE_RUN_COMMAND_OPERATION_ID,
                                        'parameters' => [
                                            'path' => ['project' => $project->id],
                                            // The trailing `chmod` is a
                                            // test-side accommodation for
                                            // a genuine host/container
                                            // boundary, not a modification
                                            // of the real script's own
                                            // behavior: DockerCommandExecutor
                                            // runs this image as root
                                            // (confirmed live -- no --user
                                            // flag exists in its docker run
                                            // invocation), so the plan.md
                                            // setup-plan.sh itself copies
                                            // lands root-owned, mode 644, on
                                            // the bind-mounted host
                                            // filesystem -- unwritable by
                                            // the non-root host process the
                                            // very next writeFile() call
                                            // (below) runs as. Appending
                                            // this leaves setup-plan.sh's
                                            // own execution and output
                                            // completely unmodified; it only
                                            // clears the way for the
                                            // separate, host-side writeFile
                                            // call this same scripted turn
                                            // also issues.
                                            'body' => ['command' => '.specify/scripts/bash/setup-plan.sh --json && chmod 666 specs/001-verify-fixture/plan.md'],
                                        ],
                                    ]),
                                ],
                            ],
                            [
                                'id' => 'call_'.bin2hex(random_bytes(8)),
                                'type' => 'function',
                                'function' => [
                                    'name' => 'execute_operation',
                                    'arguments' => json_encode([
                                        'operationId' => AgentLoopService::CODING_WORKSPACE_WRITE_FILE_OPERATION_ID,
                                        'parameters' => [
                                            'path' => ['project' => $project->id],
                                            'body' => [
                                                'path' => self::EXPECTED_RELATIVE_PLAN_PATH,
                                                'content' => self::PLAN_CONTENT,
                                            ],
                                        ],
                                    ]),
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
        ];

        $result = $this->app->make(AgentLoopService::class)->run(
            $conversationFixture->conversation->fresh(),
            $promptText
        );

        $this->assertSame('completed', $result['status'], 'the scripted run must reach a normal turn boundary');

        // --- Step 7 (AS2/FR-005): the real file exists on the real
        // fixture filesystem, with the scripted content -- never a
        // mocked write assertion.
        $realPlanPath = $this->speckitFixture->rootPath.'/'.self::EXPECTED_RELATIVE_PLAN_PATH;
        $this->assertFileExists($realPlanPath, 'setup-plan.sh --json reported this path as IMPL_PLAN');
        $this->assertSame(self::PLAN_CONTENT, file_get_contents($realPlanPath));

        // Confirm the first tool call's real, persisted result genuinely
        // reported this same path as IMPL_PLAN -- read out of the actual
        // recorded tool_data, not merely assumed because the writeFile
        // call above used it. This is what proves the precondition file
        // (Step 2) genuinely resolved the same feature directory the
        // real script used, not a coincidence of two independently
        // guessed strings.
        $assistantMessage = \ClarionApp\LlmClient\Models\Message::where('conversation_id', $conversationFixture->conversation->id)
            ->where('role', 'assistant')
            ->latest('created_at')
            ->first();
        $this->assertNotNull($assistantMessage);
        $runCommandResult = $assistantMessage->tool_data['tool_results'][0]['content'] ?? null;
        $this->assertIsString($runCommandResult);
        $implPlan = $this->extractReportedImplPlanPath(json_decode($runCommandResult, true));
        $this->assertNotNull($implPlan, 'the real setup-plan.sh --json output must report an IMPL_PLAN path');
        $this->assertSame(
            '/workspace/'.self::EXPECTED_RELATIVE_PLAN_PATH,
            $implPlan,
            'the real reported IMPL_PLAN path must match the feature directory this fixture set up'
        );

        // --- Step 8: ledger.
        $ledger = new SpecKitOutcomeLedger();
        $ledger->expectInvoked('copilot', 'speckit.plan');
        $ledger->observeInvoked('copilot', 'speckit.plan');
        $ledger->reconcile();
    }

    /**
     * Given the decoded runCommand tool-result envelope (AgentLoopService
     * ::buildCommandOutputEnvelope()'s shape -- status/exit_code/etc.
     * plus a CommandOutputPromptBuilder-wrapped 'output' block),
     * regex-extracts the real, literal IMPL_PLAN value setup-plan.sh
     * --json actually printed to stdout.
     */
    private function extractReportedImplPlanPath(?array $envelope): ?string
    {
        if (!is_array($envelope) || !isset($envelope['output']) || !is_string($envelope['output'])) {
            return null;
        }

        if (preg_match('/"IMPL_PLAN"\s*:\s*"([^"]*)"/', $envelope['output'], $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Seeds a fake OpenAPI operation catalog so
     * CodingAgentProvisioner::ensureForUser() -> AgentDefinitionParser can
     * resolve coding.yaml's tools.allow/confirmation_required entries
     * (Grounding note 12), mirroring
     * CommandExecutionConfirmationJourneyTest::seedOperationCatalog()
     * exactly.
     */
    private function seedOperationCatalog(): void
    {
        $operations = [
            'clarionApp.llmClient.codingWorkspace.listFiles' => ['path' => '/coding-project/{project}/files', 'method' => 'get'],
            'clarionApp.llmClient.codingWorkspace.readFile' => ['path' => '/coding-project/{project}/file', 'method' => 'get'],
            'clarionApp.llmClient.codingWorkspace.writeFile' => ['path' => '/coding-project/{project}/file', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.deleteFile' => ['path' => '/coding-project/{project}/file', 'method' => 'delete'],
            'clarionApp.llmClient.codingWorkspace.runTests' => ['path' => '/coding-project/{project}/run-tests', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.runCommand' => ['path' => '/coding-project/{project}/run-command', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.runCode' => ['path' => '/coding-project/{project}/run-code', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.gitStatus' => ['path' => '/coding-project/{project}/git-status', 'method' => 'get'],
            'clarionApp.llmClient.codingWorkspace.gitDiff' => ['path' => '/coding-project/{project}/git-diff', 'method' => 'get'],
            'clarionApp.llmClient.codingWorkspace.gitCommit' => ['path' => '/coding-project/{project}/git-commit', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.gitPush' => ['path' => '/coding-project/{project}/git-push', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.gitBranch' => ['path' => '/coding-project/{project}/git-branch', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.gitRewriteHistory' => ['path' => '/coding-project/{project}/git-rewrite-history', 'method' => 'post'],
        ];

        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $operationId,
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

    /**
     * Routes the outbound HTTP call the agent loop's tool executor makes
     * straight into the real, unmodified CodingWorkspaceController via the
     * container, mirroring
     * CommandExecutionConfirmationJourneyTest::fakeCodingWorkspaceHttp()
     * exactly (Grounding note 10). DockerCommandExecutor is left
     * completely unbound/unmocked here -- unlike that file, this test
     * genuinely wants the real, Docker-backed runCommand path.
     */
    private function fakeCodingWorkspaceHttp(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = strtoupper($request->method());

            if (!preg_match('#/([^/]+)/(files|file|run-tests|run-command|git-status|git-diff)$#', $path, $m)) {
                return Http::response(['error' => 'unmapped test route: '.$path], 500);
            }
            [, $projectId, $suffix] = $m;

            $laravelRequest = in_array($method, ['POST', 'PUT', 'PATCH'], true)
                ? Request::create($request->url(), $method, $request->data())
                : Request::create($request->url(), $method);

            $controller = app(CodingWorkspaceController::class);

            $response = match (true) {
                $suffix === 'files' && $method === 'GET' => $controller->listFiles($laravelRequest, $projectId),
                $suffix === 'file' && $method === 'GET' => $controller->readFile($laravelRequest, $projectId),
                $suffix === 'file' && $method === 'POST' => $controller->writeFile($laravelRequest, $projectId),
                $suffix === 'file' && $method === 'DELETE' => $controller->deleteFile($laravelRequest, $projectId),
                $suffix === 'run-tests' && $method === 'POST' => $controller->runTests($laravelRequest, $projectId),
                $suffix === 'run-command' && $method === 'POST' => $controller->runCommand($laravelRequest, $projectId),
                $suffix === 'git-status' && $method === 'GET' => $controller->gitStatus($laravelRequest, $projectId),
                $suffix === 'git-diff' && $method === 'GET' => $controller->gitDiff($laravelRequest, $projectId),
                default => response()->json(['error' => 'unmapped test route: '.$suffix.' '.$method], 500),
            };

            return Http::response($response->getData(true), $response->getStatusCode());
        });
    }
}
