<?php

namespace Tests\RealSpecKit;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\CodingAgentProvisioner;
use ClarionApp\LlmClient\Services\McpPromptRegistry;
use Dedoc\Scramble\Generator;
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
 * 129-speckit-verify-acceptance, Phase 7 (US4), T031 -- quickstart.md
 * Scenario 4 in full: `speckit.clarify`'s real, unmodified instructions
 * genuinely direct presenting clarification questions to the user and
 * waiting on the answer before proceeding, and that pattern reaches the
 * ordinary conversational turn boundary (not a tool-call confirmation
 * pause) -- proven by driving AgentLoopService::run() twice on the same
 * conversation: once for the question, once for the "user's" answer.
 *
 * Live-verification note (research.md D6 was re-checked against the real,
 * freshly-fetched .github/agents/speckit.clarify.agent.md during this
 * file's implementation, per FR-007's own "confirmed against the real
 * Spec-Kit project's actual instructions rather than assuming it in
 * advance" -- two things D6 states turned out not to match the real body
 * verbatim, so the assertions below quote the actual text instead of D6's
 * paraphrase):
 *
 *   1. D6 quotes the real body as saying "Wait for user to respond...
 *      Present all questions together before waiting for responses." No
 *      such sentence exists anywhere in the real file. The real
 *      instructions do the opposite of "present all questions together":
 *      step 5 is a *sequential* loop that says, verbatim, "Present EXACTLY
 *      ONE question at a time." The word "wait" appears twice in the real
 *      file, both times inside the unrelated extension-hook sections (of
 *      the form "wait for the result of the hook command"), never in the
 *      user-question exchange itself. The real, load-bearing signal that
 *      the command *waits for the user's answer before continuing* is
 *      structural, not a literal "wait": step 5's loop only advances to
 *      the next queued question, and step 6's spec-file integration only
 *      happens, "After the user answers:" -- i.e. the instructions
 *      describe no path that proceeds without an answer in hand. The
 *      assertions below therefore check the real, present strings
 *      ("Present EXACTLY ONE question at a time", "After the user
 *      answers:") rather than the non-existent "wait"/"respond" pairing
 *      D6 anticipated.
 *   2. D6/tasks.md's "up to 5 numbered questions in a fixed markdown
 *      format" is also not literal -- the real file bounds the count at 5
 *      ("Maximum of 5 total questions across the whole session", "up to 5
 *      highly targeted clarification questions" in the frontmatter
 *      description) but never numbers the questions for the user (they
 *      are asked one at a time, not rendered as a numbered list); "fixed
 *      markdown format" instead refers to the mandatory `**Question:**`
 *      lead-in, an optional `**Recommended:**`/options table for
 *      multiple-choice questions, and a closing "You can reply with the
 *      option letter..." sentence -- all real, quoted verbatim below.
 *
 *   research.md D6's other claim -- that this pattern reaches the normal
 *   conversational turn boundary rather than a writeFile/runCommand
 *   confirmation pause, because speckit.clarify's own documented flow
 *   never calls either tool from the questioning loop itself -- held up:
 *   the real file's only file-writing step (step 8, "Write the updated
 *   spec back to FEATURE_SPEC") happens only after the questioning loop
 *   has already finished, so this test's two scripted turns (a question,
 *   then an answer) never need to script a tool call at all, and
 *   `confirmation_relaxed` never comes into play.
 *
 *   Also confirmed live (a discrepancy tasks.md T031 step 2 did not
 *   anticipate): speckit.clarify's own step 1 *does* run
 *   `.specify/scripts/bash/check-prerequisites.sh --json --paths-only`,
 *   and that script *does* require a resolvable feature directory (either
 *   a `SPECIFY_FEATURE_DIRECTORY` env var or `.specify/feature.json`),
 *   erroring "ERROR: Feature directory not found." exactly like
 *   setup-plan.sh (research.md D5) when neither is present -- so
 *   speckit.clarify is NOT precondition-free the way tasks.md's framing
 *   suggested. What IS true, and is what actually makes the
 *   `.specify/feature.json` precondition unnecessary for *this test*: the
 *   scripted fake model never issues a runCommand tool call for
 *   check-prerequisites.sh at all (per the point above, both scripted
 *   turns are plain finalAnswer() text), so that script is never actually
 *   executed here, and the precondition it would need is simply never
 *   reached.
 */
#[Group('real-speckit-cli')]
class UserQuestionJourneyTest extends AssembledSystemTestCase
{
    private ?SpecKitFixtureProject $speckitFixture = null;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            SpecKitCliFixtureBuilder::assertAvailable();
        } catch (EnvironmentUnavailableException $e) {
            $this->markTestSkipped($e->getMessage());
        }
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
    public function a_command_that_asks_the_user_something_reaches_the_user_and_continues(): void
    {
        $this->scenario = 'user_question_journey';
        $this->entryPath = 'sync';

        // --- Step 2: a real, fresh specify init fixture. No
        // .specify/feature.json precondition -- see the class docblock's
        // live-verification note: the real check-prerequisites.sh script
        // this command's own step 1 would run is never actually executed
        // by this test (no runCommand is ever scripted below).
        $this->speckitFixture = (new SpecKitCliFixtureBuilder())->build('copilot', '--commands');

        // --- Step 3 (Grounding note 11): fixture conversation + real
        // coding-agent wiring, identical in shape to
        // CommandArgumentInvocationJourneyTest's T019 steps 2-4, minus the
        // feature.json precondition (not needed -- see above) and minus
        // fakeCodingWorkspaceHttp() (not needed either -- no tool call is
        // ever scripted in this scenario).
        $conversationFixture = $this->fixture()->build();
        $this->actingAs($conversationFixture->user, 'api');

        $this->seedOperationCatalog();
        $agent = app(CodingAgentProvisioner::class)->ensureForUser($conversationFixture->user->id);

        $project = CodingProject::create([
            'user_id' => $conversationFixture->user->id,
            'name' => 'speckit user question journey',
            'root_path' => $this->speckitFixture->rootPath,
            'test_command' => null,
            'confirmation_relaxed' => true,
        ]);

        $conversationFixture->conversation->update([
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
            'coding_project_id' => $project->id,
        ]);

        // --- Step 3 (T031 step 3): fetch the real speckit.clarify
        // instructions and confirm, against the actual fetched text, that
        // they direct presenting a question (one at a time, up to 5) and
        // only proceed once the user has answered.
        $prompt = (new McpPromptRegistry())->getPrompt(
            'speckit.clarify',
            [],
            codingProjectId: $project->id,
            userId: $conversationFixture->user->id,
        );

        $this->assertNotNull($prompt, 'speckit.clarify must resolve against the real fixture project');
        $promptText = $prompt['messages'][0]['content']['text'];

        $this->assertStringContainsString(
            'Present EXACTLY ONE question at a time',
            $promptText,
            'the real, unmodified speckit.clarify instructions must genuinely direct presenting one question at a time'
        );
        $this->assertStringContainsString(
            'Maximum of 5 total questions across the whole session',
            $promptText,
            'the real instructions must genuinely bound the question count at 5'
        );
        $this->assertStringContainsString(
            'After the user answers:',
            $promptText,
            'the real instructions must genuinely gate integration on having received the user\'s answer first'
        );
        $this->assertStringContainsString(
            '**Question:**',
            $promptText,
            'the real instructions must genuinely direct the fixed **Question:** lead-in format'
        );

        // --- Step 5 (AS1/FR-007): script the fake model's finalAnswer()
        // to contain a clarification question mirroring the real
        // template's own documented multiple-choice question format
        // (step 5 of the real body, quoted above: **Question:** lead-in,
        // **Recommended:** line, an Option/Description table, and the
        // closing "reply with the option letter" sentence).
        $questionText = <<<'TEXT'
**Question:** Should failed notification deliveries retry with exponential backoff? (FR-014)
Why it matters: this determines how quickly a failed delivery is retried and how much load a flaky downstream service sees under sustained failure.

**Recommended:** Option A - exponential backoff bounds retry storms while still recovering quickly from transient failures.

| Option | Description |
|--------|-------------|
| A | Exponential backoff, capped at 5 attempts |
| B | Fixed-interval retry every 30 seconds |
| Short | Provide a different short answer (<=5 words) |

You can reply with the option letter (e.g., "A"), accept the recommendation by saying "yes" or "recommended", or provide your own short answer.
TEXT;

        $this->script()->finalAnswer($questionText);

        $result = $this->app->make(AgentLoopService::class)->run(
            $conversationFixture->conversation->fresh(),
            $promptText
        );

        $this->assertSame(
            'completed',
            $result['status'],
            'presenting a clarification question must reach the ordinary conversational turn boundary, not a pending tool-call confirmation pause'
        );

        $firstAssistantMessage = Message::where('conversation_id', $conversationFixture->conversation->id)
            ->where('role', 'assistant')
            ->latest('created_at')
            ->first();
        $this->assertNotNull($firstAssistantMessage, 'the scripted clarification question must be persisted as an assistant message');
        $this->assertStringContainsString(
            'Should failed notification deliveries retry with exponential backoff?',
            $firstAssistantMessage->content,
            'the persisted assistant message must contain the scripted clarification question text'
        );

        // --- Step 6 (AS2/FR-007): a second turn, scripting the "user's"
        // concrete answer as the next user message, proves the command
        // continues using it rather than stalling or ignoring it.
        $answerText = 'A - use exponential backoff, capped at 5 attempts, for FR-014.';

        $this->script()->finalAnswer(
            'Understood -- recording your answer for FR-014 (exponential backoff, capped at 5 attempts) and continuing.'
        );

        $secondResult = $this->app->make(AgentLoopService::class)->run(
            $conversationFixture->conversation->fresh(),
            $answerText
        );

        $this->assertSame('completed', $secondResult['status']);

        $secondPayload = $this->capturedChatPayloads()[count($this->capturedChatPayloads()) - 1] ?? null;
        $this->assertNotNull($secondPayload, 'the second scripted turn must have produced a captured chat payload');

        $secondTurnMessageHistory = json_encode($secondPayload->messages);
        $this->assertStringContainsString(
            $answerText,
            $secondTurnMessageHistory,
            'the second turn\'s captured payload message history must contain the supplied answer text, proving the command continued using it'
        );

        // --- Step 7: ledger.
        $ledger = new SpecKitOutcomeLedger();
        $ledger->expectInvoked('copilot', 'speckit.clarify');
        $ledger->observeInvoked('copilot', 'speckit.clarify');
        $ledger->reconcile();
    }

    /**
     * Seeds a fake OpenAPI operation catalog so
     * CodingAgentProvisioner::ensureForUser() -> AgentDefinitionParser can
     * resolve coding.yaml's tools.allow/confirmation_required entries
     * (Grounding note 12), mirroring
     * CommandArgumentInvocationJourneyTest::seedOperationCatalog() exactly
     * -- needed regardless of whether any tool is actually invoked during
     * the run, since provisioning validates the catalog up front.
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
}
