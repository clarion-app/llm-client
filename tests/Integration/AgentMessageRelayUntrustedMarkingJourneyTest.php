<?php

namespace Tests\Integration;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentMessage;
use ClarionApp\LlmClient\Services\AgentMessageQuery;
use ClarionApp\LlmClient\Services\AgentMessageService;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageContentPart;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageEnvelope;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 107-agent-message-protocol, Phase 5 (US3, T025), quickstart.md §3:
 * US3/FR-011/FR-012/FR-013 end-to-end. Content marked `external: true` in
 * message 1 is read back via AgentMessageQuery::findMessage(), decoded
 * through a genuine toArray()/fromArray() round trip through the
 * agent_messages.content JSON column, carried unchanged into message 2's
 * context, sent via a second, independent send() call, and confirmed still
 * marked external:true — byte-for-byte — on message 2's persisted row.
 *
 * Per research.md D4 this is expected to already pass against Phases 3-4's
 * existing implementation with zero new production code, but per T025's
 * own instructions it must actually be run and its real outcome recorded,
 * not assumed.
 */
class AgentMessageRelayUntrustedMarkingJourneyTest extends TestCase
{
    private User $user;

    private Agent $agentA;

    private Agent $agentB;

    private Agent $agentC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

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

        $this->agentC = Agent::create([
            'user_id' => $this->user->id,
            'name' => 'Agent C',
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

    public function test_external_marking_survives_a_genuine_two_hop_relay_through_persistence_and_readback(): void
    {
        $externalPart = new MessageContentPart('search result payload from outside the installation', external: true);
        $trustedPart = new MessageContentPart('summarize the following', external: false);

        // Hop 1: A -> B, carrying an externally-sourced part in content.
        $outcome1 = $this->service()->send(new MessageEnvelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: [$trustedPart, $externalPart],
            context: [],
            expectedResponse: 'relay the external part onward, still marked',
            ownerUserId: $this->user->id,
            conversationId: null,
        ));

        // Read message 1 back via the owner-scoped query path — not the raw
        // model — to exercise the same read surface a real relay would use.
        $row1 = $this->query()->findMessage($this->user->id, $outcome1->agentMessageId);
        $this->assertNotNull($row1, 'Precondition: message 1 must be readable back for this test to prove anything');

        // Locate the persisted external part among message 1's decoded
        // content array (a genuine toArray()/fromArray() round trip through
        // the agent_messages.content JSON column, not the original PHP object).
        $persistedExternalArray = null;
        foreach ($row1->content as $partArray) {
            if (($partArray['external'] ?? false) === true) {
                $persistedExternalArray = $partArray;
                break;
            }
        }
        $this->assertNotNull($persistedExternalArray, 'Precondition: the external part must round-trip into message 1\'s persisted content');
        $this->assertSame($externalPart->text, $persistedExternalArray['text']);
        $this->assertTrue($persistedExternalArray['external']);

        // Rehydrate it into a MessageContentPart via fromArray() — the same
        // shape a real relaying agent would carry forward — and use it,
        // unchanged, as one entry of message 2's context.
        $relayedPart = MessageContentPart::fromArray($persistedExternalArray);
        $this->assertTrue($relayedPart->external);
        $this->assertSame($externalPart->text, $relayedPart->text);

        // Hop 2: B -> C, a genuine second, independent send() call, carrying
        // the relayed part in context (not content) this time.
        $outcome2 = $this->service()->send(new MessageEnvelope(
            fromAgentId: $this->agentB->id,
            toAgentId: $this->agentC->id,
            content: [new MessageContentPart('here is context from an earlier hop')],
            context: [$relayedPart],
            expectedResponse: 'ack, still marked external',
            ownerUserId: $this->user->id,
            conversationId: null,
        ));

        $row2 = $this->query()->findMessage($this->user->id, $outcome2->agentMessageId);
        $this->assertNotNull($row2);

        $this->assertCount(1, $row2->context);
        $this->assertSame($externalPart->text, $row2->context[0]['text']);
        $this->assertTrue(
            $row2->context[0]['external'],
            'The external marking must survive unchanged across the genuine two-send()-call relay'
        );

        // Also assert AgentMessage::find() (the raw model, not the query
        // wrapper) agrees, ruling out any query-layer-only artifact.
        $rawRow2 = AgentMessage::find($outcome2->agentMessageId);
        $this->assertNotNull($rawRow2);
        $this->assertTrue($rawRow2->context[0]['external']);
        $this->assertSame($externalPart->toArray(), $rawRow2->context[0]);
    }
}
