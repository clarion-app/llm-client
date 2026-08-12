<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
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
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * A ceiling stop has to leave a conversation genuinely usable, not merely
 * closed out cleanly on paper. Two properties are proven here, each with
 * its own scenario:
 *
 *  - a message sent after a stop is accepted and processed like any other
 *    message — no lingering is_processing flag, no malformed history sent
 *    to the provider on the next round-trip, no repair step the caller has
 *    to perform first;
 *  - the stop's own window is not reset by the act of sending that new
 *    message — a conversation resumed while its window is still open
 *    continues to be measured against the count it already accumulated.
 *
 * Time is frozen for the whole file so both scenarios exercise a single,
 * still-open window rather than depending on wall-clock timing.
 */
class ConversationWorkResumedAfterStopJourneyTest extends TestCase
{
    private const UNEXECUTED_TOOL_RESULT = "This tool call was not executed: the conversation's per-response work ceiling was reached.";

    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

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

    /**
     * The exact shape the next request against either provider's
     * chat-completions endpoint needs: every assistant message carrying
     * tool_calls must be immediately followed by one tool-role message per
     * tool_call_id, with none left dangling.
     */
    private function assertHistoryIsWireValid(array $history): void
    {
        $count = count($history);

        for ($i = 0; $i < $count; $i++) {
            $msg = $history[$i];

            if (($msg['role'] ?? null) !== 'assistant' || empty($msg['tool_calls'])) {
                continue;
            }

            $expectedIds = array_map(fn ($call) => $call['id'] ?? '', $msg['tool_calls']);
            sort($expectedIds);

            $actualIds = [];
            $j = $i + 1;
            while ($j < $count && ($history[$j]['role'] ?? null) === 'tool') {
                $actualIds[] = $history[$j]['tool_call_id'] ?? '';
                $j++;
            }
            sort($actualIds);

            $this->assertSame(
                $expectedIds,
                $actualIds,
                "The assistant message at history index {$i} must have every tool_call answered by an immediately following tool message"
            );
        }
    }

    // ---------------------------------------------------------------
    // Scenario 1 — accepted and processed normally, no repair needed
    // ---------------------------------------------------------------

    #[Test]
    public function a_new_message_after_a_ceiling_stop_is_accepted_and_processed_normally(): void
    {
        $this->declareConversationDefault(5, 60);
        $conversation = $this->newConversation();

        $service = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(7)]]]],
            ['choices' => [['message' => ['content' => 'Sure, happy to continue.', 'tool_calls' => []]]]],
        ]);

        $stopResult = $service->run($conversation, 'Do seven things in one go.');

        $this->assertSame('stopped', $stopResult['status']);
        $this->assertSame('conversation_work_ceiling_reached', $stopResult['code'] ?? null);

        $conversation->refresh();
        $this->assertFalse($conversation->is_processing, 'is_processing must already be cleared by the stop itself');

        // Wire-valid the moment the stop happens — before the next message
        // is ever sent, not merely after some corrective step.
        $this->assertHistoryIsWireValid($service->buildHistoryMessages($conversation));

        $resumedResult = $service->run($conversation, 'Please continue.');

        $this->assertSame(
            'completed',
            $resumedResult['status'],
            'A new message sent after a ceiling stop must be accepted and processed normally, not rejected'
        );
        $this->assertNotSame('conversation_work_ceiling_reached', $resumedResult['code'] ?? null);

        $conversation->refresh();
        $this->assertFalse($conversation->is_processing);

        $this->assertHistoryIsWireValid($service->buildHistoryMessages($conversation));
    }

    // ---------------------------------------------------------------
    // Scenario 2 — the still-open window is not reset by resuming
    // ---------------------------------------------------------------

    #[Test]
    public function a_conversation_resumed_within_the_same_window_is_still_measured_against_it(): void
    {
        $this->declareConversationDefault(5, 60);
        $conversation = $this->newConversation();

        $service = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(7)]]]],
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(1)]]]],
        ]);

        $stopResult = $service->run($conversation, 'Do seven things in one go.');
        $this->assertSame('stopped', $stopResult['status']);

        // Time has not advanced (frozen in setUp): the window this stop
        // happened in is still the current window. A resumed message
        // attempting just one more work unit must be refused on its very
        // first attempt if — and only if — the earlier count carried over
        // rather than being reset by the new message.
        $resumedResult = $service->run($conversation, 'One more thing, please.');

        $this->assertSame('stopped', $resumedResult['status']);
        $this->assertSame('conversation_work_ceiling_reached', $resumedResult['code'] ?? null);

        $message = Message::find($resumedResult['message_id']);
        $this->assertNotNull($message);
        $this->assertSame(
            self::UNEXECUTED_TOOL_RESULT,
            $message->tool_data['tool_results'][0]['content'] ?? null,
            'The resumed turn\'s very first work unit must already be over the carried-over count from the earlier stop'
        );

        $conversation->refresh();
        $this->assertFalse($conversation->is_processing);
    }
}
