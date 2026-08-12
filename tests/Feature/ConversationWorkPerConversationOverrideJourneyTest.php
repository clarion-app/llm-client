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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * An operator raises, lowers, or waives the work ceiling for one specific
 * conversation without touching the ceiling that applies to any other
 * conversation, and the change is live on that conversation's very next
 * work unit — no restart, no window reset.
 *
 * Four conversations carry the story:
 *
 *  - A is raised well above the general default and can perform far more
 *    work than it allows.
 *  - B is lowered below the general default and is stopped almost
 *    immediately; while B is stopped, its ceiling is raised again and the
 *    very next work unit reflects that change without its window count
 *    starting over.
 *  - C is waived outright and is never stopped no matter how much work it
 *    attempts.
 *  - D is never touched at all and remains bound by the general default
 *    throughout every change made to A, B, and C.
 *
 * Every step also confirms the conversations *not* being changed keep
 * resolving to exactly the ceiling they had a moment before — a change to
 * one conversation's row must be invisible to every other lookup.
 */
class ConversationWorkPerConversationOverrideJourneyTest extends TestCase
{
    private const REFUSAL_TEXT = "This tool call was not executed: the conversation's per-response work ceiling was reached.";

    private User $operator;
    private User $nonOperator;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.run_trace.enabled' => false]);

        $this->operator = User::factory()->create();
        $this->nonOperator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->actingAs($this->operator)->putJson($this->conversationDefaultEndpoint(), [
            'max_work_units' => 5,
            'window_seconds' => 60,
        ])->assertStatus(200);
    }

    protected function tearDown(): void
    {
        DB::table('conversation_work_ceilings')->delete();
        DB::table('agent_runs')->delete();
        DB::table('users')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function base(): string
    {
        return '/api/clarion-app/llm-client/conversation-work-ceilings';
    }

    private function conversationDefaultEndpoint(): string
    {
        return $this->base().'/conversation-default';
    }

    private function conversationEndpoint(string $conversationId): string
    {
        return $this->base().'/conversations/'.$conversationId;
    }

    private function newConversation(): Conversation
    {
        // A pre-set title: a completed conversation with a null title
        // triggers a real title-generation network call, which has nothing
        // to do with what this test drives.
        return Conversation::create([
            'user_id' => $this->operator->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);
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
     * Drive a conversation through a single turn requesting $count tool
     * calls, with a follow-up "done" response only when the whole batch is
     * expected to be admitted — a stopped batch never reaches a second
     * round-trip.
     */
    private function driveBurst(Conversation $conversation, int $count, bool $expectAllAdmitted): array
    {
        $responses = [
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst($count)]]]],
        ];

        if ($expectAllAdmitted) {
            $responses[] = ['choices' => [['message' => ['content' => 'Done.', 'tool_calls' => []]]]];
        }

        return $this->serviceWithScriptedProvider($responses)->run($conversation, 'Do several things.');
    }

    /**
     * The first $admitted entries of the named message's tool_results must
     * be real; every entry after that must be the synthesized refusal.
     * Identified by the message id run() itself returns, never by "latest
     * created_at" — two messages for the same conversation can share a
     * one-second timestamp within a single test.
     */
    private function assertBatchAdmittedExactly(string $messageId, int $totalCalls, int $admitted): void
    {
        $message = Message::find($messageId);

        $this->assertNotNull($message);
        $this->assertCount($totalCalls, $message->tool_data['tool_calls']);

        $results = $message->tool_data['tool_results'];
        $this->assertCount($totalCalls, $results);

        foreach ($results as $index => $result) {
            if ($index < $admitted) {
                $this->assertNotSame(self::REFUSAL_TEXT, $result['content'], "Tool call at index {$index} must have actually executed");
            } else {
                $this->assertSame(self::REFUSAL_TEXT, $result['content'], "Tool call at index {$index} must be refused as not executed");
            }
        }
    }

    // ---------------------------------------------------------------
    // The scenario
    // ---------------------------------------------------------------

    #[Test]
    public function raising_lowering_waiving_and_reverting_one_conversations_ceiling_affects_only_that_conversation(): void
    {
        $conversationA = $this->newConversation();
        $conversationB = $this->newConversation();
        $conversationC = $this->newConversation();
        $conversationD = $this->newConversation();
        $service = app(ConversationWorkCeilingService::class);

        // ---- Raise A well above the default -----------------------------
        $this->actingAs($this->operator)->putJson($this->conversationEndpoint($conversationA->id), [
            'max_work_units' => 100,
            'window_seconds' => 60,
        ])->assertStatus(200);

        $this->assertSame(100, $service->resolveForConversation($conversationA->id)->max_work_units);
        $this->assertSame('conversation_default', $service->resolveForConversation($conversationB->id)->scope_type, 'B must be untouched by A\'s raise');
        $this->assertSame('conversation_default', $service->resolveForConversation($conversationC->id)->scope_type, 'C must be untouched by A\'s raise');
        $this->assertSame(5, $service->resolveForConversation($conversationD->id)->max_work_units, 'D must be untouched by A\'s raise');

        // ---- Lower B well below the default ------------------------------
        $this->actingAs($this->operator)->putJson($this->conversationEndpoint($conversationB->id), [
            'max_work_units' => 1,
            'window_seconds' => 60,
        ])->assertStatus(200);

        $this->assertSame(1, $service->resolveForConversation($conversationB->id)->max_work_units);
        $this->assertSame(100, $service->resolveForConversation($conversationA->id)->max_work_units, 'A must be untouched by B\'s lowering');
        $this->assertSame('conversation_default', $service->resolveForConversation($conversationC->id)->scope_type, 'C must be untouched by B\'s lowering');
        $this->assertSame(5, $service->resolveForConversation($conversationD->id)->max_work_units, 'D must be untouched by B\'s lowering');

        // ---- Waive C entirely ----------------------------------------------
        $this->actingAs($this->operator)->putJson($this->conversationEndpoint($conversationC->id), [
            'waived' => true,
        ])->assertStatus(200);

        $this->assertNull($service->resolveForConversation($conversationC->id), 'A waived conversation has no enforceable ceiling');
        $this->assertTrue($service->applicableConversationRow($conversationC->id)->waived);
        $this->assertSame(100, $service->resolveForConversation($conversationA->id)->max_work_units, 'A must be untouched by C\'s waiver');
        $this->assertSame(1, $service->resolveForConversation($conversationB->id)->max_work_units, 'B must be untouched by C\'s waiver');
        $this->assertSame(5, $service->resolveForConversation($conversationD->id)->max_work_units, 'D must be untouched by C\'s waiver');

        // ---- A performs far more work than the default would allow --------
        $resultA = $this->driveBurst($conversationA, 10, expectAllAdmitted: true);
        $this->assertSame('completed', $resultA['status'], 'A, raised to 100, must not be stopped after 10 work units');

        // ---- B is stopped almost immediately at its own lowered ceiling ---
        $resultB = $this->driveBurst($conversationB, 3, expectAllAdmitted: false);
        $this->assertSame('stopped', $resultB['status']);
        $this->assertSame('conversation_work_ceiling_reached', $resultB['code'] ?? null);
        $this->assertBatchAdmittedExactly($resultB['message_id'], 3, admitted: 1);

        // ---- C is never stopped no matter how much work it attempts -------
        $resultC = $this->driveBurst($conversationC, 20, expectAllAdmitted: true);
        $this->assertSame('completed', $resultC['status'], 'C, waived, must never be stopped');

        // ---- Raising B's ceiling while it is stopped takes effect on the
        //      very next work unit, with no window reset: B's window count
        //      already stands at 2 (one admitted, one refused, from the
        //      burst above), so raising the ceiling to 3 must admit exactly
        //      one more work unit before refusing again — if the window had
        //      reset instead, both of the next two tool calls would fit
        //      under the new ceiling and the whole batch would be admitted.
        $this->actingAs($this->operator)->putJson($this->conversationEndpoint($conversationB->id), [
            'max_work_units' => 3,
            'window_seconds' => 60,
        ])->assertStatus(200);

        $resultBRaised = $this->driveBurst($conversationB, 2, expectAllAdmitted: false);
        $this->assertSame(
            'stopped',
            $resultBRaised['status'],
            'B must still be stopped this window — a fresh count would have admitted both new tool calls under the raised ceiling of 3'
        );
        $this->assertBatchAdmittedExactly($resultBRaised['message_id'], 2, admitted: 1);

        $this->assertSame(100, $service->resolveForConversation($conversationA->id)->max_work_units, 'A must be untouched by B\'s raise');
        $this->assertNull($service->resolveForConversation($conversationC->id), 'C must be untouched by B\'s raise');
        $this->assertSame(5, $service->resolveForConversation($conversationD->id)->max_work_units, 'D must be untouched by B\'s raise');

        // ---- Removing A's override reverts it to the default on its next
        //      work unit: A's window count already stands at 10 from the
        //      burst above, so once bound by the default of 5 again, even a
        //      single further tool call must be refused.
        $this->actingAs($this->operator)->deleteJson($this->conversationEndpoint($conversationA->id))->assertStatus(204);

        $this->assertSame('conversation_default', $service->resolveForConversation($conversationA->id)->scope_type);
        $this->assertSame(5, $service->resolveForConversation($conversationA->id)->max_work_units);

        $resultAReverted = $this->driveBurst($conversationA, 1, expectAllAdmitted: false);
        $this->assertSame('stopped', $resultAReverted['status'], 'A must now be bound by the default, whose window it already exceeded');
        $this->assertBatchAdmittedExactly($resultAReverted['message_id'], 1, admitted: 0);

        $this->assertSame(3, $service->resolveForConversation($conversationB->id)->max_work_units, 'B must be untouched by A\'s revert');
        $this->assertNull($service->resolveForConversation($conversationC->id), 'C must be untouched by A\'s revert');
        $this->assertSame(5, $service->resolveForConversation($conversationD->id)->max_work_units, 'D must be untouched by A\'s revert');

        // ---- D, never touched by any of the above, is still bound by the
        //      general default throughout.
        $resultD = $this->driveBurst($conversationD, 6, expectAllAdmitted: false);
        $this->assertSame('stopped', $resultD['status']);
        $this->assertBatchAdmittedExactly($resultD['message_id'], 6, admitted: 5);
    }

    #[Test]
    public function a_non_operator_cannot_raise_lower_or_remove_a_per_conversation_override(): void
    {
        $conversationId = (string) Str::uuid();

        $this->actingAs($this->nonOperator)->putJson($this->conversationEndpoint($conversationId), [
            'max_work_units' => 1,
            'window_seconds' => 1,
        ])->assertStatus(403);

        $this->actingAs($this->nonOperator)->deleteJson($this->conversationEndpoint($conversationId))->assertStatus(403);

        $this->assertSame(
            0,
            DB::table('conversation_work_ceilings')->where('scope_type', 'conversation')->where('scope_id', $conversationId)->count(),
            'A refused write or delete must create or change nothing for that conversation'
        );
    }
}
