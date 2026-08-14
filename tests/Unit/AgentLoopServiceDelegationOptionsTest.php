<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 098-delegation-protocol, Phase 5 (US4), tasks.md T036.
 *
 * Unit tests for the two new, additive `AgentLoopService::run()` $options
 * keys research.md D3 introduces:
 *
 * - `$options['max_iterations']` overrides
 *   `config('llm-client.agent_loop.max_iterations')` for that one call
 *   only -- the shared config value itself is never mutated.
 * - `$options['deadline_at']` (a Carbon instant) is checked at the top of
 *   the existing `for` loop, before any per-iteration work. Once reached,
 *   it exits through the exact same close-out shape the existing
 *   max-iterations branch already uses (Grounding note item 1's own
 *   L1254-1278 shape) -- `RunEndState::StoppedEarly`, the conversation
 *   marked not-processing, the open step (if any) and the run closed --
 *   but with a distinct `end_reason` ("Delegation time bound reached")
 *   and a distinct returned `code` ("time_ceiling_reached").
 *
 * Also proves the regression guarantee D3 promises: every existing call
 * site's behavior -- a plain `run($conversation, $message)` with no
 * `$options` at all, or an `$options` array present but omitting both new
 * keys -- is byte-identical to before this feature. The two new keys must
 * be true no-ops when absent, not merely "usually absent."
 *
 * Mirrors ConversationWorkCompositionJourneyTest's own established
 * scripted-LlmProvider/toolCallBurst convention for driving `run()` end to
 * end against a mocked provider without a live HTTP call.
 *
 * Written before `run()` reads either new key -- every test below is
 * expected to FAIL red: `max_iterations` in $options is silently ignored
 * today (the loop always runs out the plain config ceiling), and
 * `deadline_at` is never read at all, so a past deadline never stops
 * anything and the mocked provider's `chat()` (asserted `never()` in the
 * deadline test) would in fact be called.
 */
class AgentLoopServiceDelegationOptionsTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        // ConversationWorkCompositionJourneyTest's own established
        // precedent -- buildMessagesPayload()/applyContextWindowTrim()
        // (both in the run() funnel) read this table regardless of
        // whether condensation ever actually triggers.
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('messages')->delete();
        if (Schema::hasTable('agent_run_actions')) {
            DB::table('agent_run_actions')->delete();
        }
        if (Schema::hasTable('agent_run_steps')) {
            DB::table('agent_run_steps')->delete();
        }
        if (Schema::hasTable('agent_runs')) {
            DB::table('agent_runs')->delete();
        }
        DB::table('conversations')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers (ConversationWorkCompositionJourneyTest's own
    // established convention)
    // -----------------------------------------------------------------

    private function newConversation(): Conversation
    {
        return Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            // Already titled, so a completing turn's first-exchange title
            // generation dispatch stays out of the way of what is under test.
            'title' => 'Already titled',
        ]);
    }

    private function toolCallBurstReply(int $count = 1): array
    {
        $calls = [];
        for ($i = 1; $i <= $count; $i++) {
            $calls[] = [
                'id' => 'call_'.$i.'_'.uniqid(),
                'type' => 'function',
                'function' => ['name' => 'list_applications', 'arguments' => '{}'],
            ];
        }

        return ['choices' => [['message' => ['content' => '', 'tool_calls' => $calls]]]];
    }

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
    }

    /**
     * @param array|callable $responses Either a fixed list of chat()
     *   responses consumed in order, or a closure invoked on every
     *   chat() call -- used for an effectively unbounded tool-call burst
     *   that never produces a final answer on its own, so only the
     *   ceiling actually under test can ever stop the loop.
     */
    private function serviceWithScriptedProvider(array|callable $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);

        if (is_array($responses)) {
            $provider->shouldReceive('chat')->andReturnUsing(function () use (&$responses) {
                return array_shift($responses);
            });
        } else {
            $provider->shouldReceive('chat')->andReturnUsing($responses);
        }

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

    // =================================================================
    // $options['max_iterations'] overrides config for that call only
    // =================================================================

    #[Test]
    public function max_iterations_option_overrides_the_configured_ceiling_for_that_call_only(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 20]);

        $conversation = $this->newConversation();

        $callCount = 0;
        $service = $this->serviceWithScriptedProvider(function () use (&$callCount) {
            $callCount++;

            return $this->toolCallBurstReply(1);
        });

        $result = $service->run($conversation, 'Keep going forever.', ['max_iterations' => 2]);

        $this->assertSame('error', $result['status'] ?? null);
        $this->assertSame('max_iterations', $result['code'] ?? null);
        $this->assertSame(
            2,
            $callCount,
            'an options override of 2 must stop the loop at 2 provider calls, never reaching the configured default of 20',
        );
        $this->assertSame(
            20,
            config('llm-client.agent_loop.max_iterations'),
            'passing an override via $options must never mutate the shared config value itself',
        );
    }

    #[Test]
    public function max_iterations_option_below_the_configured_default_still_records_the_stopped_early_close_out(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 20]);

        $conversation = $this->newConversation();

        $service = $this->serviceWithScriptedProvider(function () {
            return $this->toolCallBurstReply(1);
        });

        $result = $service->run($conversation, 'Keep going forever.', ['max_iterations' => 1]);

        $this->assertSame('error', $result['status'] ?? null);
        $this->assertSame('Maximum iterations reached', $result['content'] ?? null);
        $this->assertSame('max_iterations', $result['code'] ?? null);
        $this->assertNull($result['message_id'] ?? 'unset');

        $run = DB::table('agent_runs')->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($run, 'fixture sanity: the run trace must have opened a row');
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame('Maximum iterations reached', $run->end_reason);
    }

    // =================================================================
    // $options['deadline_at'] exits at the top of the very next loop
    // iteration through the max-iterations close-out shape
    // =================================================================

    #[Test]
    public function deadline_at_option_in_the_past_exits_before_any_provider_call_through_the_ceiling_close_out_shape(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 20]);

        $conversation = $this->newConversation();

        // The deadline is already in the past before run() is even
        // called, so the very first iteration's top-of-loop check must
        // trip before any per-iteration work -- including the provider
        // call itself. never() makes that assertion directly rather than
        // merely inferring it from the returned shape.
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->never();
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $service = new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            runTraceRecorder: app(RunTraceRecorder::class),
        );

        $result = $service->run($conversation, 'Deadline already passed.', [
            'deadline_at' => Carbon::now()->subMinute(),
        ]);

        $this->assertSame('error', $result['status'] ?? null);
        $this->assertSame('Delegation time bound reached', $result['content'] ?? null);
        $this->assertSame('time_ceiling_reached', $result['code'] ?? null);
        $this->assertNull($result['message_id'] ?? 'unset', 'mirrors the max-iterations close-out shape exactly -- message_id is always null');

        $run = DB::table('agent_runs')->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($run, 'fixture sanity: the run trace must have opened a row before the deadline check ever runs');
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame('Delegation time bound reached', $run->end_reason);
    }

    #[Test]
    public function deadline_at_option_never_produces_the_max_iterations_code_even_though_both_ceilings_use_the_same_close_out_shape(): void
    {
        $conversation = $this->newConversation();

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->never();
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $service = new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            runTraceRecorder: app(RunTraceRecorder::class),
        );

        $result = $service->run($conversation, 'Deadline already passed.', [
            'deadline_at' => Carbon::now()->subSeconds(30),
        ]);

        $this->assertNotSame(
            'max_iterations',
            $result['code'] ?? null,
            'a time-ceiling stop must carry its own distinct code, never be confused with the pre-existing max_iterations code',
        );
        $this->assertSame('time_ceiling_reached', $result['code'] ?? null);
    }

    // =================================================================
    // Regression: every existing call site is unaffected -- the two new
    // keys are true no-ops when absent (research.md D3)
    // =================================================================

    #[Test]
    public function run_with_no_options_at_all_still_honors_the_configured_max_iterations_ceiling_unchanged(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);

        $conversation = $this->newConversation();

        $callCount = 0;
        $service = $this->serviceWithScriptedProvider(function () use (&$callCount) {
            $callCount++;

            return $this->toolCallBurstReply(1);
        });

        $result = $service->run($conversation, 'Keep going forever.');

        $this->assertSame('error', $result['status'] ?? null);
        $this->assertSame('max_iterations', $result['code'] ?? null);
        $this->assertSame(
            2,
            $callCount,
            'a plain call with no $options argument at all must still stop exactly at the configured ceiling, identically to before this feature',
        );
    }

    #[Test]
    public function run_with_options_present_but_omitting_both_new_keys_still_honors_the_configured_ceiling_and_never_checks_a_deadline(): void
    {
        config(['llm-client.agent_loop.max_iterations' => 2]);

        $conversation = $this->newConversation();

        $callCount = 0;
        $service = $this->serviceWithScriptedProvider(function () use (&$callCount) {
            $callCount++;

            return $this->toolCallBurstReply(1);
        });

        // An $options array that legitimately exists (a pre-existing,
        // unrelated key) but carries neither 'max_iterations' nor
        // 'deadline_at' -- the two new keys must be genuine no-ops when
        // absent, not merely "usually absent."
        $result = $service->run($conversation, 'Keep going forever.', ['retry_on_validation_failure' => false]);

        $this->assertSame('error', $result['status'] ?? null);
        $this->assertSame('max_iterations', $result['code'] ?? null);
        $this->assertSame(2, $callCount);
    }

    #[Test]
    public function run_with_no_options_completes_normally_confirming_no_deadline_is_ever_checked(): void
    {
        $conversation = $this->newConversation();

        $service = $this->serviceWithScriptedProvider([
            $this->plainReply('All done, no tools needed.'),
        ]);

        $result = $service->run($conversation, 'Just answer directly.');

        $this->assertSame('completed', $result['status'] ?? null);
        $this->assertSame('All done, no tools needed.', $result['content'] ?? null);
        $this->assertNotNull($result['message_id'] ?? null);

        $run = DB::table('agent_runs')->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($run);
        $this->assertSame('completed', $run->end_state);
    }
}
