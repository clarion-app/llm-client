<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\ConsensusRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ConsensusService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 104-multi-agent-consensus, Phase 8 (Polish), tasks.md T053.
 *
 * Quickstart scenario 9 (US1/US3, research.md D8): the SAME underlying
 * contributor answers must produce the SAME reconciliation result across
 * repeated runs, regardless of the order the underlying Delegation rows are
 * read back in. Mirrors ConsensusServiceFinalizeTest's own established
 * fixture technique (real judge-role RoleAssignment + fake ProviderRegistry
 * provider, since ConsensusReconciliationJudge/RoleResolver are both
 * `final` and cannot be mocked directly).
 *
 * One FIXED set of 3 Delegation fixture rows (2 agreeing, 1 materially
 * different) is created once, sharing one batch_id. ConsensusService::
 * finalize() is then called 5 times in a row against 5 fresh
 * ConsensusRequest rows, each time handed a $batchResults array whose PHP
 * array order is freshly shuffled -- simulating a non-deterministic
 * underlying read/completion order. finalize()'s own contributor-ordering
 * query (`Delegation::whereIn($ids)->orderBy('started_at')->get()`) must
 * still hand the judge the contributors in the identical started_at
 * sequence every time, independent of $batchResults' own array order.
 */
class ConsensusReconciliationOrderingStabilityTest extends TestCase
{
    private User $user;

    private Server $server;

    private Conversation $conversation;

    /** @var Delegation[] */
    private array $fixedDelegations = [];

    private string $batchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        $agent = app(AgentService::class)->create($this->user->id, "name: ordering-agent\ninstructions: I am ordering-agent.");

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);

        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->server->id,
            'model' => 'judge-model',
        ]);

        // One FIXED set of 3 contributor answers, sharing one batch_id --
        // constructed directly as Delegation fixture rows, bypassing live
        // dispatch entirely, exactly as T053 asks for. Two agree
        // substantively (different wording, same conclusion); one is
        // materially different.
        //
        // Deliberately INSERTED out of started_at order (third-earliest
        // first, then earliest, then middle): a naive `whereIn()->get()`
        // with no explicit `orderBy('started_at')` tends to return rows in
        // insertion/rowid order on both SQLite and MySQL, which would
        // otherwise coincide with started_at order if rows were inserted
        // in chronological order -- silently making orderBy's absence
        // undetectable. Inserting out of order means a dropped orderBy is
        // actually observable (mutation-checklist row 8's own intent).
        $this->batchId = (string) Str::uuid();
        $second = $this->makeDelegation($this->batchId, 'Second contributor: there is no issue running this migration.', now()->addSeconds(5));
        $first = $this->makeDelegation($this->batchId, 'First contributor: this migration is safe to run.', now());
        $third = $this->makeDelegation($this->batchId, 'Third contributor: this migration carries real risk.', now()->addSeconds(10));
        $this->fixedDelegations = [$first, $second, $third];
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('consensus_requests')->delete();
        DB::table('agent_delegations')->delete();
        DB::table('messages')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];

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

    private function makeHelperAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeDelegation(string $batchId, string $summary, \DateTimeInterface $startedAt): Delegation
    {
        $helper = $this->makeHelperAgent('helper-'.Str::random(8));
        $helperConversation = Conversation::create([
            'user_id' => $this->user->id,
            'character' => 'Clarion',
            'agent_id' => $helper->id,
        ]);

        return Delegation::create([
            'parent_conversation_id' => $this->conversation->id,
            'parent_agent_id' => $this->conversation->agent_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => $helperConversation->id,
            'owner_user_id' => $this->user->id,
            'task' => 'Is it safe to run this migration?',
            'depth' => 1,
            'status' => 'completed',
            'batch_id' => $batchId,
            'result_status' => 'success',
            'result_summary' => $summary,
            'result_output' => json_encode([]),
            'result_undone' => '',
            'result_truncated' => false,
            'started_at' => $startedAt,
            'completed_at' => $startedAt,
        ]);
    }

    private function sixFieldResult(Delegation $delegation): array
    {
        return [
            'delegation_id' => $delegation->id,
            'helper' => 'helper',
            'status' => $delegation->result_status,
            'summary' => $delegation->result_summary,
            'output' => [],
            'undone' => $delegation->result_undone,
            'truncated' => false,
            'reason' => null,
        ];
    }

    private function makeRequest(): ConsensusRequest
    {
        return ConsensusRequest::create([
            'conversation_id' => $this->conversation->id,
            'owner_user_id' => $this->user->id,
            'coordinator_agent_id' => $this->conversation->agent_id,
            'question' => 'Is it safe to run this migration?',
            'dispatched_count' => 3,
            'quorum_required' => 2,
            'status' => 'in_progress',
            'batch_id' => $this->batchId,
            'started_at' => now(),
        ]);
    }

    private function service(): ConsensusService
    {
        return app(ConsensusService::class);
    }

    #[Test]
    public function five_runs_over_a_randomized_read_order_produce_identical_ordering_quorum_and_classification(): void
    {
        // The provider's own response is held FIXED across all 5 runs --
        // per this test's own scope (research.md D8), only the surrounding
        // pipeline (ordering, quorum arithmetic, cost math) is asserted
        // stable; the judge model's own output is not itself claimed
        // deterministic. Implemented as a callback (not a fixed
        // ->willReturn()) purely so the RAW PROMPT CONTENT the judge was
        // actually given can be captured and inspected each run --
        // mirroring ConsensusServiceFinalizeTest's own established
        // technique (judge_is_called_with_only_successful_contributors_in_started_at_order).
        // This is deliberately NOT a second, independently-ordered query
        // against the Delegation table: re-querying with its own
        // orderBy('started_at') would assert nothing about what finalize()
        // itself actually handed the judge, and would pass even if
        // finalize()'s own query dropped its ordering entirely.
        $capturedPrompts = [];
        $provider = $this->createMock(LlmProvider::class);
        $provider->method('chat')->willReturnCallback(function (array $messages) use (&$capturedPrompts) {
            $capturedPrompts[] = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            return [
                'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode([
                    'classification' => 'materially_disagreed',
                    'reconciled_answer' => 'Contributors disagree: two hold it is safe, one holds it carries real risk.',
                    'positions' => [
                        [
                            'summary' => 'Safe to proceed.',
                            'supporting' => [$this->fixedDelegations[0]->id, $this->fixedDelegations[1]->id],
                        ],
                        [
                            'summary' => 'Carries real risk.',
                            'supporting' => [$this->fixedDelegations[2]->id],
                        ],
                    ],
                ])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
                'model' => 'judge-model',
            ];
        });
        app(ProviderRegistry::class)->register(ProviderType::OpenAI, fn () => $provider);

        $capturedQuorumRequired = [];
        $capturedSuccessfulCount = [];
        $capturedClassification = [];
        $capturedPositions = [];

        for ($run = 0; $run < 5; $run++) {
            // Simulate a randomized underlying read order: shuffle the PHP
            // array order of $batchResults itself (the array finalize()
            // derives $delegationIds from) on every single run.
            $shuffled = $this->fixedDelegations;
            shuffle($shuffled);

            $batchResults = [];
            foreach ($shuffled as $index => $delegation) {
                $batchResults['call_'.$index] = $this->sixFieldResult($delegation);
            }

            $request = $this->makeRequest();

            $this->service()->finalize($request, $batchResults);

            $request->refresh();

            $capturedQuorumRequired[] = $request->quorum_required;
            $capturedSuccessfulCount[] = $request->successful_count;
            $capturedClassification[] = $request->agreement_classification;
            $capturedPositions[] = $request->disagreement_detail;
        }

        $this->assertCount(5, $capturedPrompts, 'the judge must have been called once per run');

        foreach ($capturedPrompts as $run => $prompt) {
            $posFirst = strpos($prompt, 'First contributor: this migration is safe to run.');
            $posSecond = strpos($prompt, 'Second contributor: there is no issue running this migration.');
            $posThird = strpos($prompt, 'Third contributor: this migration carries real risk.');

            $this->assertNotFalse($posFirst, "run {$run}: the earliest-started contributor's answer must reach the judge");
            $this->assertNotFalse($posSecond, "run {$run}: the middle contributor's answer must reach the judge");
            $this->assertNotFalse($posThird, "run {$run}: the latest-started contributor's answer must reach the judge");

            $this->assertLessThan(
                $posSecond,
                $posFirst,
                "run {$run}: contributors must be given to the judge in started_at order (first before second) regardless of \$batchResults' own array order"
            );
            $this->assertLessThan(
                $posThird,
                $posSecond,
                "run {$run}: contributors must be given to the judge in started_at order (second before third) regardless of \$batchResults' own array order"
            );
        }

        $this->assertSame(
            array_fill(0, 5, 2),
            $capturedQuorumRequired,
            'quorum_required must be identical across all 5 runs (pure arithmetic over a fixed input set)'
        );
        $this->assertSame(
            array_fill(0, 5, 3),
            $capturedSuccessfulCount,
            'successful_count must be identical across all 5 runs'
        );
        $this->assertSame(
            array_fill(0, 5, 'materially_disagreed'),
            $capturedClassification,
            'classification must be identical across all 5 runs given a fixed provider response'
        );

        $firstPositions = $capturedPositions[0];
        foreach ($capturedPositions as $run => $positions) {
            $this->assertSame(
                $firstPositions,
                $positions,
                "run {$run}: disagreement_detail structure must be identical across all 5 runs given a fixed provider response"
            );
        }
    }
}
