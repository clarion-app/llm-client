<?php

namespace Tests\RealSpecKit;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Controllers\CodingWorkspaceController;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\CodingAgentProvisioner;
use ClarionApp\LlmClient\Services\McpPromptRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
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
 * 129-speckit-verify-acceptance, Phase 6 (US3), T027 -- quickstart.md
 * Scenario 3 in full: invoking `speckit.plan` lets its real, unmodified
 * instructions' call to `.specify/scripts/bash/setup-plan.sh --json`
 * actually execute through the real, Docker-backed `DockerCommandExecutor`
 * path (never mocked -- unlike every other journey test's coding-agent
 * wiring, this is the one place that deliberately leaves the executor
 * unbound), and that a genuine script failure is reported with enough
 * detail to distinguish an environment mismatch from any other failure
 * (FR-006).
 *
 * Reuses CommandArgumentInvocationJourneyTest's (Phase 4/T019) fixture and
 * coding-agent wiring shape verbatim (fixture + .specify/feature.json
 * precondition + CodingAgentProvisioner + confirmation_relaxed
 * CodingProject + fakeCodingWorkspaceHttp()) -- duplicated inline here
 * rather than factored into a shared trait/base class, per tasks.md T027's
 * own "optional refactoring, not required if it adds more risk than
 * value" allowance: Phase 4's file is already green and depended on by
 * this feature's MVP checkpoint, and this phase's own bash-image /
 * Passport-token-stub / auto-stop-shortcut concerns are identical to
 * Phase 4's, so re-deriving them independently here carries more risk of
 * silently diverging than the small amount of duplication saves.
 */
#[Group('real-speckit-cli')]
class HelperScriptExecutionJourneyTest extends AssembledSystemTestCase
{
    /** @var list<string> */
    private array $fixtureRootsToClean = [];

    protected function setUp(): void
    {
        parent::setUp();

        try {
            SpecKitCliFixtureBuilder::assertAvailable();
        } catch (EnvironmentUnavailableException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $this->assertDockerAvailableOrSkip();

        // setup-plan.sh's shebang is #!/usr/bin/env bash -- the package's
        // default sandbox image (alpine:latest) has no bash, only busybox
        // ash. Same override CommandArgumentInvocationJourneyTest (Phase
        // 4) already established, to an image already pulled in this
        // environment that does carry bash.
        config(['llm-client.coding_agent.command_image' => 'nikolaik/python-nodejs:latest']);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureRootsToClean as $root) {
            if (is_dir($root)) {
                (new Process(['rm', '-rf', $root]))->run();
            }
        }

        Mockery::close();

        parent::tearDown();
    }

    /**
     * A trivial real `runCommand` call through the real, unmodified
     * CodingWorkspaceController -- independent of, and run before, any
     * Spec-Kit fixture -- so this test's own Docker dependency is
     * distinguishable from the CLI-unavailability guard above (Grounding
     * note 14, data-model.md's environment-availability handling). Uses a
     * bare throwaway temp directory, never a speckit fixture, since
     * PathContainment::validate() only requires root_path to be a real,
     * reachable directory -- nothing about its contents is checked before
     * DockerCommandExecutor runs.
     */
    private function assertDockerAvailableOrSkip(): void
    {
        $probeUser = User::factory()->create();
        $probeDir = sys_get_temp_dir().'/speckit-docker-probe-'.uniqid('', true);
        mkdir($probeDir, 0777, true);

        $probeProject = CodingProject::create([
            'user_id' => $probeUser->id,
            'name' => 'docker availability probe',
            'root_path' => $probeDir,
            'test_command' => null,
        ]);

        $this->actingAs($probeUser, 'api');

        $request = Request::create(
            '/coding-project/'.$probeProject->id.'/run-command',
            'POST',
            ['command' => 'echo hi']
        );

        $result = app(CodingWorkspaceController::class)
            ->runCommand($request, $probeProject->id)
            ->getData(true);

        (new Process(['rm', '-rf', $probeDir]))->run();

        if (($result['status'] ?? null) === 'sandbox_unavailable') {
            $this->markTestSkipped(
                'Docker unavailable: '.($result['reason'] ?? 'no reason reported by DockerCommandExecutor')
            );
        }
    }

    #[Test]
    public function invoking_speckit_plan_actually_runs_the_real_docker_backed_helper_script(): void
    {
        $this->scenario = 'helper_script_execution_success';
        $this->entryPath = 'sync';

        [$speckitFixture, $project, $conversationFixture] = $this->wireFixture('helper script execution journey');

        // --- Step 3 (AS1/FR-006): script a SINGLE runCommand tool call for
        // the real helper script -- no writeFile call needed here (unlike
        // Phase 4's scenario), since this scenario's own assertions target
        // the runCommand tool result itself, not a produced output file.
        $prompt = (new McpPromptRegistry())->getPrompt(
            'speckit.plan',
            ['command' => 'Plan the widget feature'],
            codingProjectId: $project->id,
            userId: $conversationFixture->user->id,
        );
        $this->assertNotNull($prompt, 'speckit.plan must resolve against the real fixture project');
        $promptText = $prompt['messages'][0]['content']['text'];

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
                                            'body' => ['command' => '.specify/scripts/bash/setup-plan.sh --json'],
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

        // Real, Docker-executed tool result: parse the persisted envelope,
        // never a substring match, per T027's own instruction.
        $envelope = $this->latestRunCommandEnvelope($conversationFixture);

        $this->assertSame('completed', $envelope['status'] ?? null);
        $this->assertSame(0, $envelope['exit_code'] ?? null, 'setup-plan.sh --json must exit 0 for real');

        $this->assertIsString($envelope['output'] ?? null);
        $reportedJson = $this->extractSetupPlanJson($envelope['output']);
        $this->assertNotNull($reportedJson, 'setup-plan.sh --json must have printed its JSON summary to stdout');
        $this->assertArrayHasKey('FEATURE_SPEC', $reportedJson);
        $this->assertArrayHasKey('IMPL_PLAN', $reportedJson);
        $this->assertArrayHasKey('SPECS_DIR', $reportedJson);
        $this->assertArrayHasKey('BRANCH', $reportedJson);
        $this->assertSame(
            '/workspace/specs/001-verify-fixture/plan.md',
            $reportedJson['IMPL_PLAN'],
            'the real reported IMPL_PLAN path must match the feature directory this fixture set up'
        );

        // --- Step 5: ledger.
        $ledger = new SpecKitOutcomeLedger();
        $ledger->expectInvoked('copilot', 'speckit.plan');
        $ledger->observeInvoked('copilot', 'speckit.plan');
        $ledger->reconcile();
    }

    /**
     * AS2/FR-006's second case: a genuine script failure must be reported
     * with enough detail (which script, what OS-level error) to
     * distinguish an environment mismatch from any other failure.
     *
     * DEVIATION FROM tasks.md T027 step 4's literal phrasing, verified
     * live before writing this test (not assumed): T027 names
     * `.specify/scripts/bash/common.sh` as the file to strip execute
     * permission from. Read directly, `setup-plan.sh` does
     * `source "$SCRIPT_DIR/common.sh"` -- bash's `source`/`.` builtin
     * only ever needs *read* permission on the sourced file, never
     * *execute* permission (it opens and reads the file in the current
     * shell process; it is never separately exec'd). Confirmed live,
     * twice: (1) as the host's own non-root user, `chmod -x common.sh`
     * then running setup-plan.sh directly succeeded with exit 0 and the
     * full JSON output, no error at all; (2) DockerCommandExecutor's
     * container also runs as root (no `--user` flag in its `docker run`
     * invocation, matching Phase 4's own wiring-surprise #3) -- and Linux
     * root, via CAP_DAC_OVERRIDE, additionally bypasses *read*-permission
     * checks entirely, so even stripping ALL permission bits from
     * common.sh would still leave it readable/sourceable by the
     * container's root process. There is no chmod-only mutation of
     * common.sh that can make this real script fail, under this real
     * execution model.
     *
     * `setup-plan.sh` itself, by contrast, is exec'd directly (the
     * scripted command runs it as a bare path, `sh -c
     * '.specify/scripts/bash/setup-plan.sh --json'`, confirmed against
     * DockerCommandExecutor::run()'s exact `sh -c $command` wrapper) --
     * and the Linux kernel requires at least one execute bit to be set
     * for execve() to succeed at all, a rule CAP_DAC_OVERRIDE does NOT
     * waive even for root. Confirmed live, including inside the exact
     * `nikolaik/python-nodejs:latest` image this test's config override
     * uses, running as root: `chmod 000 setup-plan.sh` then `sh -c
     * '.specify/scripts/bash/setup-plan.sh --json'` inside the container
     * prints `sh: 1: .specify/scripts/bash/setup-plan.sh: Permission
     * denied` and exits 126 -- a genuine, real, permission-denied OS
     * error naming the specific script, exactly what FR-006's second
     * acceptance scenario requires. This test therefore strips execute
     * permission from `setup-plan.sh` (the one script actually reached by
     * a direct execve in this real path) rather than `common.sh` (which
     * is merely sourced and, under root, unaffected by any chmod at all)
     * -- same "a permissions bit, not a content edit" shape T027
     * describes, applied to the file where it is actually load-bearing.
     */
    #[Test]
    public function a_helper_script_stripped_of_execute_permission_fails_with_a_named_permission_denied_error(): void
    {
        $this->scenario = 'helper_script_execution_permission_denied';
        $this->entryPath = 'sync';

        [$speckitFixture, $project, $conversationFixture] = $this->wireFixture('helper script permission denied journey');

        chmod($speckitFixture->rootPath.'/.specify/scripts/bash/setup-plan.sh', 0000);

        $prompt = (new McpPromptRegistry())->getPrompt(
            'speckit.plan',
            ['command' => 'Plan the widget feature'],
            codingProjectId: $project->id,
            userId: $conversationFixture->user->id,
        );
        $this->assertNotNull($prompt);
        $promptText = $prompt['messages'][0]['content']['text'];

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
                                            'body' => ['command' => '.specify/scripts/bash/setup-plan.sh --json'],
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

        $this->assertSame('completed', $result['status'], 'the scripted run must still reach a normal turn boundary even though the command itself failed');

        $envelope = $this->latestRunCommandEnvelope($conversationFixture);

        // AS2/FR-006: the tool result must name the specific script and a
        // permission-denied-shaped OS error -- not a generic "command
        // failed" string.
        $this->assertSame('completed', $envelope['status'] ?? null, 'DockerCommandExecutor reports a completed run with a nonzero exit code, not a distinct failure status, for an OS-level exec failure');
        $this->assertSame(126, $envelope['exit_code'] ?? null, 'a permission-denied exec failure conventionally reports exit code 126');
        $this->assertIsString($envelope['output'] ?? null);
        $this->assertStringContainsString('setup-plan.sh', $envelope['output'], 'the error must name the specific script');
        $this->assertStringContainsStringIgnoringCase(
            'permission denied',
            $envelope['output'],
            'the error must be permission-denied-shaped, not a generic "command failed" string'
        );

        // --- Step 5: a SEPARATE, independent ledger instance scoped to
        // this second fixture -- "invoked and failed" for the identical
        // (copilot, speckit.plan) pair would collide with the first test
        // method's own "invoked and succeeded" observation under the
        // ledger's write-once contract if a single instance were shared
        // across both, so each test method (each with its own fixture)
        // uses its own ledger, per T027 step 5's own instruction.
        $ledger = new SpecKitOutcomeLedger();
        $ledger->expectInvoked('copilot', 'speckit.plan');
        $ledger->observeInvoked('copilot', 'speckit.plan', failureDetail: 'setup-plan.sh stripped of execute permission -- sh: Permission denied, exit 126');
        $ledger->reconcile();
    }

    /**
     * Composes a real speckit fixture + the .specify/feature.json
     * precondition + real coding-agent/project/conversation wiring +
     * fakeCodingWorkspaceHttp(), identical in shape to
     * CommandArgumentInvocationJourneyTest (Phase 4/T019) steps 2-4 --
     * except DockerCommandExecutor itself is left completely unbound here
     * (no mock), since this is the one journey test in this feature that
     * deliberately wants the real, Docker-backed runCommand path
     * (research.md D4/D7).
     *
     * @return array{0: SpecKitFixtureProject, 1: CodingProject, 2: \Tests\Integration\Harness\ConversationFixture}
     */
    private function wireFixture(string $projectName): array
    {
        $speckitFixture = (new SpecKitCliFixtureBuilder())->build('copilot', '--commands');
        $this->fixtureRootsToClean[] = $speckitFixture->rootPath;

        file_put_contents(
            $speckitFixture->rootPath.'/.specify/feature.json',
            json_encode(['feature_directory' => 'specs/001-verify-fixture'])
        );

        $conversationFixture = $this->fixture()->build();

        $this->actingAs($conversationFixture->user, 'api');

        $this->seedOperationCatalog();
        $agent = app(CodingAgentProvisioner::class)->ensureForUser($conversationFixture->user->id);

        $project = CodingProject::create([
            'user_id' => $conversationFixture->user->id,
            'name' => $projectName,
            'root_path' => $speckitFixture->rootPath,
            'test_command' => null,
            'confirmation_relaxed' => true,
        ]);

        $conversationFixture->conversation->update([
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
            'coding_project_id' => $project->id,
        ]);

        $this->fakeCodingWorkspaceHttp();

        // Same Passport-avoidance substitution CommandArgumentInvocationJourneyTest
        // (Phase 4) established -- McpToolExecutor's default token factory
        // calls $user->createToken(), which needs a fully-provisioned
        // OAuth client this test environment never sets up.
        $this->app->instance(McpToolExecutor::class, new McpToolExecutor(
            $this->app->make(McpToolRegistry::class),
            null,
            fn ($user) => 'test-token',
        ));

        return [$speckitFixture, $project, $conversationFixture];
    }

    /**
     * Fetches the most recently persisted assistant Message's first
     * tool_result content and decodes it as the
     * AgentLoopService::buildCommandOutputEnvelope() shape (status,
     * exit_code, output, ...) -- read out of the real, persisted row, not
     * assumed.
     */
    private function latestRunCommandEnvelope($conversationFixture): array
    {
        $assistantMessage = Message::where('conversation_id', $conversationFixture->conversation->id)
            ->where('role', 'assistant')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($assistantMessage, 'a real assistant message with a runCommand tool result must have been persisted');

        $content = $assistantMessage->tool_data['tool_results'][0]['content'] ?? null;
        $this->assertIsString($content);

        $envelope = json_decode($content, true);
        $this->assertIsArray($envelope);

        return $envelope;
    }

    /**
     * Given the decoded runCommand envelope's `output` block (the
     * CommandOutputPromptBuilder-wrapped stdout/stderr text), extracts the
     * real, literal JSON object setup-plan.sh --json printed to stdout, so
     * this test can assert on the actual keys the real script emits
     * rather than a substring match.
     */
    private function extractSetupPlanJson(string $output): ?array
    {
        if (preg_match('/\{"FEATURE_SPEC".*\}/', $output, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode($matches[0], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Seeds a fake OpenAPI operation catalog so
     * CodingAgentProvisioner::ensureForUser() -> AgentDefinitionParser can
     * resolve coding.yaml's tools.allow/confirmation_required entries,
     * mirroring CommandExecutionConfirmationJourneyTest::seedOperationCatalog()
     * (and CommandArgumentInvocationJourneyTest's own copy) exactly.
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
     * container, mirroring CommandExecutionConfirmationJourneyTest::fakeCodingWorkspaceHttp()
     * exactly (Grounding note 10). DockerCommandExecutor is left
     * completely unbound/unmocked here -- this test genuinely wants the
     * real, Docker-backed runCommand path.
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
