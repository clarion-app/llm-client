<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A retry-eligible failed action is retried up to a trigger's own stated
 * retry_limit and no further; a non-transient failure is never retried at
 * all, regardless of retry_limit; an eventual success within the limit
 * closes the run as completed while the earlier failed attempts stay
 * visible in the action record rather than being hidden behind the
 * success.
 *
 * Every McpToolExecutor double this file installs throws once its own
 * call count would exceed the expected attempt ceiling for that scenario
 * -- proving "never unbounded" directly (an off-by-one or unbounded loop
 * mutation cannot silently pass by exhausting a generous iteration cap)
 * rather than merely by inference from an exact count assertion alone.
 *
 * Driven the same way as UnattendedConfirmationRefusalJourneyTest and
 * ActionRecordCompletenessJourneyTest: a real
 * AgentLoopService::run($conversation, $message, ['unattended' => true])
 * call against a scripted LlmProvider and a scripted McpToolExecutor,
 * never against RunSchedulerTriggerJob/SchedulerTrigger directly, so this
 * file's own retry_limit is passed straight through $options rather than
 * resolved from a persisted trigger row.
 *
 * Every case here is expected to be genuinely RED until AgentLoopService's
 * unattended tool-dispatch path grows the loop RetryEligibility.php's own
 * docblock already anticipates: today a failed dispatch closes its
 * ToolInvocation action Failure exactly once and control returns to the
 * ordinary per-iteration flow -- there is no re-dispatch of the same
 * operation, no shared attempt_group_id across attempts, and the run's own
 * end_state never becomes RunEndState::Failed on account of a tool
 * failure alone (only the generic \Throwable catch in run() ever produces
 * that state today).
 */
class RetryExhaustionJourneyTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate every assertion to the bound agent definition's own
        // tools.allow -- the installation-wide ceiling
        // (api_denylist/confirm_methods) is not this file's concern,
        // mirroring every sibling *JourneyTest's own established
        // convention.
        $this->app['config']->set('llm-client.confirm_methods', []);
        $this->app['config']->set('llm-client.api_denylist', []);
        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.budget.on_unpriced_model', 'admit_untracked');

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->createSupportingTables();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers -- mirrors UnattendedConfirmationRefusalJourneyTest/
    // ActionRecordCompletenessJourneyTest's own shape exactly.
    // -----------------------------------------------------------------

    private function createSupportingTables(): void
    {
        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index('user_id');
            });
        }
    }

    /**
     * @param array<string, array{path: string, method: string, summary: string}> $operations
     */
    private function seedOperationCatalog(array $operations): void
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

    /**
     * @return array{0: Agent, 1: AgentVersion, 2: Conversation}
     */
    private function bindConversation(string $yaml, string $agentName): array
    {
        $agent = Agent::create([
            'user_id' => $this->user->id,
            'name' => $agentName,
        ]);

        $version = AgentVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 1,
            'raw_definition' => $yaml,
            'content_hash' => hash('sha256', $yaml),
            'source' => AgentChangeSource::Created->value,
            'changed_by_user_id' => $this->user->id,
        ]);

        $agent->update(['current_version_id' => $version->id]);

        $conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'title' => 'Scheduled work',
            'agent_id' => $agent->id,
            'agent_version_id' => $version->id,
        ]);

        return [$agent, $version, $conversation];
    }

    /**
     * Scripts exactly one tool-call turn (the model's single decision to
     * call the flaky operation), then falls back to a plain text turn for
     * every call beyond that. A bounded per-action retry loop never
     * consumes the fallback at all -- it resolves the operation's outcome
     * (success or exhausted failure) without a further model turn, per
     * RetryEligibility.php's own docblock. Without that loop, today's
     * per-iteration flow needs a second model turn to end the run at all,
     * which is exactly what the fallback exists to supply so a red run
     * fails on a clean assertion rather than on an exhausted response
     * queue.
     */
    private function serviceWithScriptedProvider(array $responses, ?array $fallback = null): AgentLoopService
    {
        $fallback ??= $this->textResponse('(fallback turn -- should not be needed once the retry loop exists)');

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools) use (&$responses, $fallback) {
            return count($responses) > 0 ? array_shift($responses) : $fallback;
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            runTraceRecorder: app(RunTraceRecorder::class),
        );
    }

    /** A plain assistant reply carrying no tool call -- ends the run. */
    private function textResponse(string $text): array
    {
        return ['choices' => [['message' => ['content' => $text, 'tool_calls' => []]]]];
    }

    /** An assistant turn that calls execute_operation once. */
    private function toolCallResponse(string $operationId, array $parameters = []): array
    {
        return ['choices' => [['message' => ['content' => '', 'tool_calls' => [[
            'id' => 'call_' . Str::random(8),
            'type' => 'function',
            'function' => [
                'name' => 'execute_operation',
                'arguments' => json_encode(['operationId' => $operationId, 'parameters' => $parameters]),
            ],
        ]]]]]];
    }

    private function latestRunFor(Conversation $conversation): ?object
    {
        return DB::table('agent_runs')
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('created_at')
            ->first();
    }

    /** Every ToolInvocation action recorded on a run, oldest first. */
    private function toolInvocationActionsFor(string $runId): \Illuminate\Support\Collection
    {
        return DB::table('agent_run_actions')
            ->where('run_id', $runId)
            ->where('action_type', ActionType::ToolInvocation->value)
            ->orderBy('started_at')
            ->get();
    }

    /**
     * Installs a McpToolExecutor double for one GET operation whose
     * dispatch either always fails (isError, the given http-status-shaped
     * outcome) or fails on every attempt up to $succeedOnAttempt and then
     * succeeds. $maxAllowedAttempts is a hard ceiling -- one call past it
     * throws, so an unbounded or off-by-one retry loop fails this test
     * directly rather than by exhausting some generous default iteration
     * count. Returns the shared call log so a test can assert the exact
     * attempt count afterward.
     *
     * The 'status' key on each returned outcome mirrors the shape
     * RetryEligibility::isTransient() already consumes for a received
     * response (['status' => int]) -- the minimal signal a caller needs
     * to classify the outcome without depending on any particular HTTP
     * client's response object.
     *
     * An ArrayObject, not a plain array, is the return type deliberately:
     * a plain array is copied by value the moment it crosses this
     * method's own `return` boundary, so the closure below (which
     * captures the pre-return array by reference) would keep mutating a
     * copy the caller never sees. ArrayObject is a handle -- the same
     * instance the closure mutates is the one the caller receives.
     *
     * @return \ArrayObject<int, int> a live, mutating log of HTTP statuses returned, one per call, in order
     */
    private function installFlakyExecutorDouble(
        string $path,
        int $maxAllowedAttempts,
        int $failureStatus,
        ?int $succeedOnAttempt = null,
    ): \ArrayObject {
        $log = new \ArrayObject();

        $executor = Mockery::mock(McpToolExecutor::class);
        $executor->shouldReceive('extractArguments')
            ->with(Mockery::any(), $path)
            ->andReturn(['path' => $path, 'query' => [], 'body' => []]);

        $executor->shouldReceive('executeHttpCall')
            ->with('GET', $path, [], [], Mockery::any())
            ->andReturnUsing(function () use ($log, $maxAllowedAttempts, $failureStatus, $succeedOnAttempt) {
                $attempt = count($log) + 1;

                if ($attempt > $maxAllowedAttempts) {
                    throw new \RuntimeException(
                        "a bounded retry loop must never dispatch more than {$maxAllowedAttempts} attempts for one logical action -- executeHttpCall was invoked a {$attempt}th time",
                    );
                }

                if ($succeedOnAttempt !== null && $attempt === $succeedOnAttempt) {
                    $log[] = 200;

                    return [
                        'content' => [['type' => 'text', 'text' => json_encode(['status' => 'ok'])]],
                        'isError' => false,
                        'status' => 200,
                    ];
                }

                $log[] = $failureStatus;

                return [
                    'content' => [['type' => 'text', 'text' => json_encode(['error' => "Upstream responded {$failureStatus}"])]],
                    'isError' => true,
                    'status' => $failureStatus,
                ];
            });

        $this->app->instance(McpToolExecutor::class, $executor);

        return $log;
    }

    /**
     * Installs a McpToolExecutor double that throws the instant it is
     * called at all -- for the case where dispatch must never happen in
     * the first place (an unattended refusal), rather than happening once
     * and then never retrying.
     */
    private function installNeverDispatchedExecutorDouble(): void
    {
        $executor = Mockery::mock(McpToolExecutor::class);
        $executor->shouldReceive('extractArguments')->andReturnUsing(function () {
            throw new \RuntimeException('an unattended refusal must stop the run before any dispatch is attempted');
        });
        $executor->shouldReceive('executeHttpCall')->andReturnUsing(function () {
            throw new \RuntimeException('an unattended refusal must stop the run before any dispatch is attempted');
        });

        $this->app->instance(McpToolExecutor::class, $executor);
    }

    // -----------------------------------------------------------------
    // Row 7/8 -- exhaustion: exactly retry_limit + 1 attempts, never more
    // -----------------------------------------------------------------

    #[Test]
    public function a_transiently_failing_operation_is_retried_exactly_retry_limit_plus_one_times_then_the_run_fails(): void
    {
        $this->seedOperationCatalog([
            'scheduler.flaky_read' => ['path' => '/api/scheduler/flaky', 'method' => 'get', 'summary' => 'Occasionally unavailable read'],
        ]);

        [, , $conversation] = $this->bindConversation(
            "name: retry-agent\ninstructions: Do the defined work.\ntools:\n  allow:\n    - scheduler.flaky_read\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n",
            'retry-agent',
        );

        $retryLimit = 2; // N -- expect exactly N + 1 = 3 attempts, never 2, never 4+.
        $log = $this->installFlakyExecutorDouble('/api/scheduler/flaky', maxAllowedAttempts: $retryLimit + 1, failureStatus: 503);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.flaky_read'),
            $this->textResponse('failed: scheduler.flaky_read never recovered after retrying.'),
        ]);

        $result = $service->run($conversation, 'Do the defined work.', [
            'unattended' => true,
            'retry_limit' => $retryLimit,
        ]);

        $this->assertCount(
            $retryLimit + 1,
            $log,
            'a persistently-transient failure must be attempted exactly retry_limit + 1 times, not retry_limit and not unbounded; got ' . count($log) . ' attempt(s)',
        );

        $run = $this->latestRunFor($conversation);
        $this->assertNotNull($run);
        $this->assertSame(
            RunEndState::Failed->value,
            $run->end_state,
            "an exhausted retry sequence must close the run RunEndState::Failed; got '{$run->end_state}' -- current run() return: " . json_encode($result),
        );
        $this->assertNotNull($run->end_reason);
        $this->assertStringContainsString(
            'scheduler.flaky_read',
            (string) $run->end_reason,
            'the failure reason must name the exhausted action',
        );

        $actions = $this->toolInvocationActionsFor($run->id);
        $this->assertCount($retryLimit + 1, $actions, 'the action record must show one ToolInvocation row per attempt');

        $groupIds = $actions->pluck('attempt_group_id')->unique();
        $this->assertCount(1, $groupIds, 'every attempt of the same logical action must share one attempt_group_id');
        $this->assertNotNull($groupIds->first(), 'the shared attempt_group_id must not be null');

        foreach ($actions as $action) {
            $this->assertSame(
                ActionOutcome::Failure->value,
                $action->outcome,
                'every attempt in an exhausted retry sequence is individually a failure',
            );
        }
    }

    // -----------------------------------------------------------------
    // Row 9 -- a non-transient failure is retried zero times
    // -----------------------------------------------------------------

    #[Test]
    public function a_well_formed_4xx_failure_is_never_retried_regardless_of_retry_limit(): void
    {
        $this->seedOperationCatalog([
            'scheduler.bad_argument' => ['path' => '/api/scheduler/widget', 'method' => 'get', 'summary' => 'Rejects a bad argument'],
        ]);

        [, , $conversation] = $this->bindConversation(
            "name: retry-agent-4xx\ninstructions: Do the defined work.\ntools:\n  allow:\n    - scheduler.bad_argument\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n",
            'retry-agent-4xx',
        );

        // A generous retry_limit -- RetryEligibility::isTransient() must
        // be the thing that stops this at one attempt, not the ceiling.
        $retryLimit = 5;
        $log = $this->installFlakyExecutorDouble('/api/scheduler/widget', maxAllowedAttempts: 1, failureStatus: 422);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.bad_argument'),
            $this->textResponse('failed: scheduler.bad_argument was rejected.'),
        ]);

        $service->run($conversation, 'Do the defined work.', [
            'unattended' => true,
            'retry_limit' => $retryLimit,
        ]);

        $this->assertCount(
            1,
            $log,
            'a well-formed 4xx (never transient) must be attempted exactly once, regardless of a much larger retry_limit; got ' . count($log) . ' attempt(s)',
        );

        $run = $this->latestRunFor($conversation);
        $this->assertNotNull($run);
        $this->assertSame(
            RunEndState::Failed->value,
            $run->end_state,
            "a non-transient failure must still close the run RunEndState::Failed on its first and only attempt; got '{$run->end_state}'",
        );
    }

    // -----------------------------------------------------------------
    // Mutual exclusivity -- an unattended refusal never reaches dispatch,
    // so it is never retried either (contracts/retry-and-notification.md's
    // own "mutually exclusive outcomes" note; already guaranteed by
    // UnattendedConfirmationRefusalJourneyTest's refusal branch, restated
    // here so this file's own retry_limit can never be read as
    // overriding it).
    // -----------------------------------------------------------------

    #[Test]
    public function an_unpermitted_operation_is_refused_and_never_dispatched_regardless_of_retry_limit(): void
    {
        $this->seedOperationCatalog([
            'scheduler.forbidden_op' => ['path' => '/api/scheduler/forbidden', 'method' => 'get', 'summary' => 'Not in the allow list'],
        ]);

        [, , $conversation] = $this->bindConversation(
            "name: retry-agent-refusal\ninstructions: Do only the defined work.\ntools:\n  allow: []\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n",
            'retry-agent-refusal',
        );

        $this->installNeverDispatchedExecutorDouble();

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.forbidden_op'),
        ]);

        $result = $service->run($conversation, 'Do only the defined work.', [
            'unattended' => true,
            'retry_limit' => 10,
        ]);

        // installNeverDispatchedExecutorDouble() would have thrown from
        // inside the dispatch itself had it ever been reached, which
        // run()'s own catch(\Throwable) would have turned into an 'error'
        // status rather than 'stopped_unauthorized' -- so this one
        // assertion already proves dispatch never happened.
        $this->assertSame('stopped_unauthorized', $result['status'] ?? null);

        // The refusal still opens and closes exactly one ToolInvocation
        // action (UnattendedConfirmationRefusalJourneyTest's own
        // assertNoActionFollowsTheRefusedAttemptExceptNotification()
        // relies on this same bookkeeping row existing) -- it is opened
        // before the permission check, then closed Failure by the refusal
        // itself, never by a dispatch attempt. One row, one failure, no
        // retry loop involved at all.
        $run = $this->latestRunFor($conversation);
        $this->assertNotNull($run);
        $actions = $this->toolInvocationActionsFor($run->id);
        $this->assertCount(1, $actions, 'the refusal itself is recorded as a single ToolInvocation action, not a dispatched-and-retried one');
        $this->assertSame(ActionOutcome::Failure->value, $actions[0]->outcome);
    }

    // -----------------------------------------------------------------
    // Row 10 -- an eventual success within the limit is reported as
    // success, with the earlier failed attempts still visible
    // -----------------------------------------------------------------

    #[Test]
    public function an_operation_that_fails_then_succeeds_within_the_limit_closes_completed_with_the_failed_attempts_still_visible(): void
    {
        $this->seedOperationCatalog([
            'scheduler.eventually_ok' => ['path' => '/api/scheduler/eventually-ok', 'method' => 'get', 'summary' => 'Recovers after one failure'],
        ]);

        [, , $conversation] = $this->bindConversation(
            "name: retry-agent-recovers\ninstructions: Do the defined work.\ntools:\n  allow:\n    - scheduler.eventually_ok\nsafety:\n  confirmation_required: []\n  unattended_authorized: []\n",
            'retry-agent-recovers',
        );

        $retryLimit = 2; // N -- fails N - 1 = 1 time, then succeeds on attempt 2.
        $log = $this->installFlakyExecutorDouble(
            '/api/scheduler/eventually-ok',
            maxAllowedAttempts: $retryLimit + 1,
            failureStatus: 503,
            succeedOnAttempt: 2,
        );

        // A follow-up text turn IS a legitimate part of the green-state
        // shape here (unlike the two failure cases above): once the
        // retried call itself succeeds, the run continues exactly like
        // any other successful tool call, and an unattended run's own
        // completion still requires one more model turn to produce its
        // report (Phase 7/US5's own established behaviour).
        $service = $this->serviceWithScriptedProvider([
            $this->toolCallResponse('scheduler.eventually_ok'),
            $this->textResponse('Recovered after one retry, work is done.'),
        ]);

        $result = $service->run($conversation, 'Do the defined work.', [
            'unattended' => true,
            'retry_limit' => $retryLimit,
        ]);

        $this->assertCount(
            2,
            $log,
            'the operation must be attempted exactly twice -- one failure, one success -- not left at one attempt and not retried past the point it succeeded',
        );

        $run = $this->latestRunFor($conversation);
        $this->assertNotNull($run);
        $this->assertSame(
            RunEndState::Completed->value,
            $run->end_state,
            "an eventual success within the retry limit must close the run RunEndState::Completed; got '{$run->end_state}' -- current run() return: " . json_encode($result),
        );

        $actions = $this->toolInvocationActionsFor($run->id);
        $this->assertCount(
            2,
            $actions,
            'the action record must show both attempts -- the earlier failure must not be hidden behind the eventual success',
        );

        $groupIds = $actions->pluck('attempt_group_id')->unique();
        $this->assertCount(1, $groupIds, 'both attempts of the same logical action must share one attempt_group_id');
        $this->assertNotNull($groupIds->first());

        $this->assertSame(ActionOutcome::Failure->value, $actions[0]->outcome, 'the first attempt is still recorded as a failure');
        $this->assertSame(ActionOutcome::Success->value, $actions[1]->outcome, 'the second, successful attempt is recorded as a success');
    }
}
