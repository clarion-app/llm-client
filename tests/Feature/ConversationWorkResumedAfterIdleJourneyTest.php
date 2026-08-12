<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationWorkCeilingService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * The fixed window resets purely on wall-clock time — windowStart =
 * intdiv(now, windowSeconds) * windowSeconds — so a conversation resumed
 * after its prior window has genuinely closed is measured against a fresh
 * count, never against a tally an earlier, now-elapsed window left behind.
 * No separate idle-timer, no last-activity column: the idle threshold is
 * window_seconds itself, the same number that defines the window.
 *
 * Two scenarios bound this window's edge from both sides:
 *
 *  - resumed after more than window_seconds has elapsed with no activity:
 *    the resumed turn lands in a brand-new, never-touched bucket, and the
 *    earlier window's own count is left exactly as it was, untouched;
 *  - resumed while the window is still open: the resumed turn continues to
 *    be measured against the count it already accumulated, not reset.
 *
 * Both scenarios assert directly against the literal cache key
 * ConversationWorkCounter computes, not merely the admitted/refused
 * outcome — a coincidentally-correct outcome could otherwise hide a
 * windowStart computation that is broken in some other way.
 */
class ConversationWorkResumedAfterIdleJourneyTest extends TestCase
{
    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // Minute-aligned so intdiv($now, $windowSeconds) * $windowSeconds
        // equals $now itself for a 60s window, keeping the arithmetic in
        // this test's own assertions exact rather than approximate.
        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('conversation_work_ceilings')->delete();
        DB::table('agent_runs')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function newConversation(): Conversation
    {
        return Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            // Already titled, so a normally-completed turn's first-exchange
            // title generation stays out of the way of what is under test.
            'title' => 'Already titled',
        ]);
    }

    private function declareConversationDefault(int $maxWorkUnits, int $windowSeconds): void
    {
        app(ConversationWorkCeilingService::class)->upsert(
            ConversationWorkScope::ConversationDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_work_units' => $maxWorkUnits, 'window_seconds' => $windowSeconds],
        );
    }

    private function toolCallBurst(int $count): array
    {
        $calls = [];
        for ($i = 1; $i <= $count; $i++) {
            $calls[] = [
                'id' => "call_{$i}",
                'type' => 'function',
                'function' => ['name' => 'list_applications', 'arguments' => '{}'],
            ];
        }

        return $calls;
    }

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn(...$responses);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
        );
    }

    private function counterKey(string $conversationId, int $windowSeconds, int $windowStart): string
    {
        return "llm-client:conversation-work:{$conversationId}:{$windowSeconds}:{$windowStart}";
    }

    // ---------------------------------------------------------------
    // Scenario 1 — resumed after the window has genuinely closed
    // ---------------------------------------------------------------

    #[Test]
    public function a_conversation_resumed_after_the_window_has_closed_gets_a_fresh_count(): void
    {
        $windowSeconds = 60;
        $this->declareConversationDefault(5, $windowSeconds);
        $conversation = $this->newConversation();

        $firstWindowStart = intdiv(Carbon::now()->timestamp, $windowSeconds) * $windowSeconds;
        $firstKey = $this->counterKey($conversation->id, $windowSeconds, $firstWindowStart);

        $firstService = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(3)]]]],
            ['choices' => [['message' => ['content' => 'Done for now.', 'tool_calls' => []]]]],
        ]);

        $firstResult = $firstService->run($conversation, 'Do three things.');

        $this->assertSame(
            'completed',
            $firstResult['status'],
            '3 of a 5-work-unit ceiling must complete without tripping it.'
        );
        $this->assertTrue(Cache::has($firstKey), "The first window's own counter key must have been written.");
        $this->assertSame(
            3,
            Cache::get($firstKey),
            'The first window must have counted exactly the 3 tool calls it executed.'
        );

        // Let more than window_seconds pass with no activity — time
        // travelled, never slept, so this stays fast and deterministic.
        Carbon::setTestNow(Carbon::now()->addSeconds($windowSeconds + 1));

        $secondWindowStart = intdiv(Carbon::now()->timestamp, $windowSeconds) * $windowSeconds;
        $secondKey = $this->counterKey($conversation->id, $windowSeconds, $secondWindowStart);

        $this->assertNotSame(
            $firstWindowStart,
            $secondWindowStart,
            'Enough time must have passed for the window bucket itself to change, not merely the clock.'
        );
        $this->assertNotSame(
            $firstKey,
            $secondKey,
            "The resumed turn's cache key must differ from the earlier turn's — not merely its admitted count."
        );
        $this->assertFalse(
            Cache::has($secondKey),
            "The resumed window's own counter key must not exist yet — untouched by the earlier window's activity."
        );

        // A burst of 4 in the resumed turn only fully succeeds if the count
        // starts fresh at 0 (reaching 4, still <= 5). If the earlier
        // window's count of 3 had carried over instead, the third of these
        // four calls would push the running total to 6 and be refused.
        $resumedService = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(4)]]]],
            ['choices' => [['message' => ['content' => 'All done.', 'tool_calls' => []]]]],
        ]);

        $resumedResult = $resumedService->run($conversation, 'Do four more things.');

        $this->assertSame(
            'completed',
            $resumedResult['status'],
            'The resumed turn must admit a fresh 4 work units, not be limited to the 5 - 3 = 2 that would remain '
            .'if the earlier window\'s count had carried over.'
        );
        $this->assertNotSame('conversation_work_ceiling_reached', $resumedResult['code'] ?? null);

        $this->assertTrue(
            Cache::has($secondKey),
            "The resumed turn must have written into the new window's own key."
        );
        $this->assertSame(
            4,
            Cache::get($secondKey),
            'The resumed window must count only its own 4 work units, starting from 0.'
        );

        // The earlier window's own key is left exactly as it was — its TTL
        // (2x window_seconds = 120s) has not elapsed after a 61s jump, and
        // nothing about the resumed turn ever touches it.
        $this->assertSame(
            3,
            Cache::get($firstKey),
            "The earlier window's own counter must remain unchanged by the resumed turn."
        );
    }

    // ---------------------------------------------------------------
    // Scenario 2 — the converse: resumed while the window is still open
    // ---------------------------------------------------------------

    #[Test]
    public function a_conversation_resumed_within_the_same_window_keeps_the_same_key_and_carries_its_count(): void
    {
        $windowSeconds = 60;
        $this->declareConversationDefault(5, $windowSeconds);
        $conversation = $this->newConversation();

        $windowStart = intdiv(Carbon::now()->timestamp, $windowSeconds) * $windowSeconds;
        $key = $this->counterKey($conversation->id, $windowSeconds, $windowStart);

        $firstService = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(3)]]]],
            ['choices' => [['message' => ['content' => 'Done for now.', 'tool_calls' => []]]]],
        ]);

        $firstResult = $firstService->run($conversation, 'Do three things.');
        $this->assertSame('completed', $firstResult['status']);
        $this->assertSame(3, Cache::get($key));

        // No time passes at all — still well inside the same window.
        $stillWindowStart = intdiv(Carbon::now()->timestamp, $windowSeconds) * $windowSeconds;
        $this->assertSame(
            $windowStart,
            $stillWindowStart,
            'Precondition: no time has passed, so the window bucket must not have changed.'
        );

        // A burst of 4 more, within the same still-open window, pushes the
        // running total past 5 on its third call (3 + 3 = 6 > 5) if — and
        // only if — the count carried over rather than resetting.
        $resumedService = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(4)]]]],
        ]);

        $resumedResult = $resumedService->run($conversation, 'Do four more things.');

        $this->assertSame(
            'stopped',
            $resumedResult['status'],
            'A conversation resumed within the same still-open window must still be measured against the count '
            .'it already accumulated, not given a fresh count.'
        );
        $this->assertSame('conversation_work_ceiling_reached', $resumedResult['code'] ?? null);

        $this->assertSame(
            $key,
            $this->counterKey($conversation->id, $windowSeconds, intdiv(Carbon::now()->timestamp, $windowSeconds) * $windowSeconds),
            "The resumed turn's key must be the identical key the earlier turn wrote to — no new bucket."
        );
        $this->assertSame(
            6,
            Cache::get($key),
            'The window must show the carried-over total (3 + 3 admitted before the stop), not a fresh count.'
        );
    }
}
