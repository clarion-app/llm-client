<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentMessage;
use ClarionApp\LlmClient\Services\AgentMessageQuery;
use ClarionApp\LlmClient\Services\AgentMessageService;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageContentPart;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageEnvelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 107-agent-message-protocol, Phase 3 (US1, T013), data-model.md §3,
 * contracts/agent-message-service.md §2, spec.md US1's own Independent
 * Test.
 *
 * Written before AgentMessageService/AgentMessageQuery exist — every test
 * below is expected to FAIL red (class not found) until Phase 3's
 * implementation (T014/T015) adds them.
 *
 * Two structurally different MessageEnvelopes are sent (one single content
 * part / empty context; another with several content parts, one marked
 * external: true, and non-empty context) and both, read back via
 * AgentMessageQuery::findMessage(), must expose the identical column/field
 * set with nothing arrangement-specific about either — the entire point of
 * this feature (US1 AC1/AC2). A third envelope built the same general shape
 * but naming different agents proves a new "arrangement" needs no new
 * message shape (US1 AC3) — this test file, sending three structurally
 * distinct envelopes through the one send() method with no per-envelope
 * branching, IS that proof (research.md D1: no production arrangement
 * exists yet to retrofit).
 */
class AgentMessageQueryTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private Agent $agentA;

    private Agent $agentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->agentA = Agent::create([
            'user_id' => $this->user->id,
            'name' => 'Agent A',
            'current_version_id' => null,
        ]);

        $this->agentB = Agent::create([
            'user_id' => $this->user->id,
            'name' => 'Agent B',
            'current_version_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('agent_messages')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function service(): AgentMessageService
    {
        return app(AgentMessageService::class);
    }

    private function query(): AgentMessageQuery
    {
        return app(AgentMessageQuery::class);
    }

    #[Test]
    public function two_structurally_different_envelopes_persist_the_identical_column_set(): void
    {
        $conversationId = (string) Str::uuid();

        // Envelope 1: a single content part, empty context.
        $envelope1 = new MessageEnvelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: [new MessageContentPart('a single fact')],
            context: [],
            expectedResponse: 'an acknowledgement',
            ownerUserId: $this->user->id,
            conversationId: $conversationId,
        );

        // Envelope 2: several content parts (one external: true) and
        // non-empty context — a structurally different "arrangement" shape.
        $envelope2 = new MessageEnvelope(
            fromAgentId: $this->agentB->id,
            toAgentId: $this->agentA->id,
            content: [
                new MessageContentPart('first part'),
                new MessageContentPart('second, externally sourced part', external: true),
            ],
            context: [
                new MessageContentPart('some background context'),
            ],
            expectedResponse: 'a detailed report',
            ownerUserId: $this->user->id,
            conversationId: $conversationId,
        );

        $outcome1 = $this->service()->send($envelope1);
        $outcome2 = $this->service()->send($envelope2);

        $row1 = $this->query()->findMessage($this->user->id, $outcome1->agentMessageId);
        $row2 = $this->query()->findMessage($this->user->id, $outcome2->agentMessageId);

        $this->assertInstanceOf(AgentMessage::class, $row1);
        $this->assertInstanceOf(AgentMessage::class, $row2);

        // Identical column/field set — no arrangement-specific extra field
        // on either row (US1 AC1/AC2).
        $this->assertSame(array_keys($row1->getAttributes()), array_keys($row2->getAttributes()));

        $this->assertSame('delivered', $row1->status);
        $this->assertSame($this->agentA->id, $row1->from_agent_id);
        $this->assertSame($this->agentB->id, $row1->to_agent_id);
        $this->assertSame($this->user->id, $row1->owner_user_id);
        $this->assertSame($conversationId, $row1->conversation_id);
        $this->assertSame([['text' => 'a single fact', 'external' => false]], $row1->content);
        $this->assertSame([], $row1->context);
        $this->assertSame('an acknowledgement', $row1->expected_response);
        $this->assertNull($row1->refusal_reason);

        $this->assertSame('delivered', $row2->status);
        $this->assertSame($this->agentB->id, $row2->from_agent_id);
        $this->assertSame($this->agentA->id, $row2->to_agent_id);
        $this->assertSame($this->user->id, $row2->owner_user_id);
        $this->assertSame($conversationId, $row2->conversation_id);
        $this->assertSame(
            [
                ['text' => 'first part', 'external' => false],
                ['text' => 'second, externally sourced part', 'external' => true],
            ],
            $row2->content,
        );
        $this->assertSame([['text' => 'some background context', 'external' => false]], $row2->context);
        $this->assertSame('a detailed report', $row2->expected_response);
        $this->assertNull($row2->refusal_reason);
    }

    #[Test]
    public function a_third_envelope_naming_different_agents_needs_no_new_message_shape(): void
    {
        // A third, independent "arrangement" — same envelope shape, just a
        // different pair of agents on each side (US1 AC3).
        $envelope = new MessageEnvelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: [new MessageContentPart('yet another arrangement')],
            context: [],
            expectedResponse: 'confirmation',
            ownerUserId: $this->user->id,
            conversationId: null,
        );

        $outcome = $this->service()->send($envelope);
        $row = $this->query()->findMessage($this->user->id, $outcome->agentMessageId);

        $this->assertInstanceOf(AgentMessage::class, $row);
        $this->assertSame('delivered', $row->status);
        $this->assertNull($row->conversation_id);
    }

    #[Test]
    public function find_message_returns_null_for_nonexistent_id(): void
    {
        $this->assertNull($this->query()->findMessage($this->user->id, (string) Str::uuid()));
    }

    #[Test]
    public function find_message_returns_null_for_another_users_message(): void
    {
        $envelope = new MessageEnvelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: [new MessageContentPart('owned by user A')],
            context: [],
            expectedResponse: 'ack',
            ownerUserId: $this->user->id,
            conversationId: null,
        );

        $outcome = $this->service()->send($envelope);

        $this->assertNull($this->query()->findMessage($this->otherUser->id, $outcome->agentMessageId));
    }

    #[Test]
    public function messages_for_owner_returns_every_message_sent_or_received_newest_first(): void
    {
        $envelope1 = new MessageEnvelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: [new MessageContentPart('first message')],
            context: [],
            expectedResponse: 'ack',
            ownerUserId: $this->user->id,
            conversationId: null,
        );

        $envelope2 = new MessageEnvelope(
            fromAgentId: $this->agentB->id,
            toAgentId: $this->agentA->id,
            content: [new MessageContentPart('second message')],
            context: [],
            expectedResponse: 'ack',
            ownerUserId: $this->user->id,
            conversationId: null,
        );

        $outcome1 = $this->service()->send($envelope1);
        // Force a distinguishable created_at ordering — the fixture DB
        // timestamp resolution can otherwise tie two inserts in the same
        // request.
        AgentMessage::where('id', $outcome1->agentMessageId)->update([
            'created_at' => now()->subMinute(),
        ]);
        $outcome2 = $this->service()->send($envelope2);

        $messages = $this->query()->messagesForOwner($this->user->id);

        $this->assertCount(2, $messages);
        $this->assertSame($outcome2->agentMessageId, $messages[0]->id);
        $this->assertSame($outcome1->agentMessageId, $messages[1]->id);
    }

    #[Test]
    public function messages_for_owner_returns_empty_array_never_null_when_there_are_none(): void
    {
        $messages = $this->query()->messagesForOwner($this->user->id);

        $this->assertIsArray($messages);
        $this->assertSame([], $messages);
    }
}
