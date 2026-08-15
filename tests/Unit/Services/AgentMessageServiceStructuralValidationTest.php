<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Services\AgentMessageService;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageContentPart;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageEnvelope;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageRefusal;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 107-agent-message-protocol, Phase 6 (US4, T029), contracts §1 step 1 and
 * quickstart.md's test inventory: AgentMessageService::send()'s structural
 * validation step — checked FIRST, before the size bound (research.md D6)
 * — refuses a message missing fromAgentId/toAgentId/non-empty
 * content/non-blank expectedResponse, persisting a refused row and
 * returning MessageRefusal. An empty context array is explicitly NOT a
 * refusal (FR-005 — "explicitly no context" vs. a missing part).
 *
 * Written before AgentMessageService::send() has any structural-validation
 * branch (Phase 3-5 only implement unconditional delivery) — expected to
 * FAIL red until T030's implementation.
 */
class AgentMessageServiceStructuralValidationTest extends TestCase
{
    private User $user;

    private Agent $agentA;

    private Agent $agentB;

    protected function setUp(): void
    {
        parent::setUp();

        Context::forget('run_id');

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
    }

    protected function tearDown(): void
    {
        Context::forget('run_id');

        DB::table('agent_messages')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function service(): AgentMessageService
    {
        return app(AgentMessageService::class);
    }

    private function envelope(
        ?string $fromAgentId,
        ?string $toAgentId,
        array $content,
        array $context,
        ?string $expectedResponse,
        ?string $conversationId = null,
    ): MessageEnvelope {
        return new MessageEnvelope(
            fromAgentId: $fromAgentId,
            toAgentId: $toAgentId,
            content: $content,
            context: $context,
            expectedResponse: $expectedResponse,
            ownerUserId: $this->user->id,
            conversationId: $conversationId,
        );
    }

    private function wellFormedContent(): array
    {
        return [new MessageContentPart('a fact')];
    }

    #[Test]
    public function missing_sender_is_refused(): void
    {
        $envelope = $this->envelope(
            fromAgentId: null,
            toAgentId: $this->agentB->id,
            content: $this->wellFormedContent(),
            context: [],
            expectedResponse: 'an acknowledgement',
        );

        $outcome = $this->service()->send($envelope);

        $this->assertInstanceOf(MessageRefusal::class, $outcome);
        $this->assertSame('refused', $outcome->status());
        $this->assertSame('missing_sender', $outcome->reason);

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame('refused', $row->status);
        $this->assertSame('missing_sender', $row->refusal_reason);
        $this->assertNull($row->from_agent_id);
        $this->assertSame($this->agentB->id, $row->to_agent_id);
    }

    #[Test]
    public function empty_string_sender_is_refused(): void
    {
        $envelope = $this->envelope(
            fromAgentId: '',
            toAgentId: $this->agentB->id,
            content: $this->wellFormedContent(),
            context: [],
            expectedResponse: 'an acknowledgement',
        );

        $outcome = $this->service()->send($envelope);

        $this->assertInstanceOf(MessageRefusal::class, $outcome);
        $this->assertSame('missing_sender', $outcome->reason);
    }

    #[Test]
    public function missing_recipient_is_refused(): void
    {
        $envelope = $this->envelope(
            fromAgentId: $this->agentA->id,
            toAgentId: null,
            content: $this->wellFormedContent(),
            context: [],
            expectedResponse: 'an acknowledgement',
        );

        $outcome = $this->service()->send($envelope);

        $this->assertInstanceOf(MessageRefusal::class, $outcome);
        $this->assertSame('refused', $outcome->status());
        $this->assertSame('missing_recipient', $outcome->reason);

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame('refused', $row->status);
        $this->assertSame('missing_recipient', $row->refusal_reason);
        $this->assertSame($this->agentA->id, $row->from_agent_id);
        $this->assertNull($row->to_agent_id);
    }

    #[Test]
    public function missing_content_is_refused(): void
    {
        $envelope = $this->envelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: [],
            context: [],
            expectedResponse: 'an acknowledgement',
        );

        $outcome = $this->service()->send($envelope);

        $this->assertInstanceOf(MessageRefusal::class, $outcome);
        $this->assertSame('refused', $outcome->status());
        $this->assertSame('missing_content', $outcome->reason);

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame('refused', $row->status);
        $this->assertSame('missing_content', $row->refusal_reason);
        $this->assertSame([], json_decode($row->content, true));
    }

    #[Test]
    public function missing_expected_response_is_refused(): void
    {
        $envelope = $this->envelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: $this->wellFormedContent(),
            context: [],
            expectedResponse: null,
        );

        $outcome = $this->service()->send($envelope);

        $this->assertInstanceOf(MessageRefusal::class, $outcome);
        $this->assertSame('refused', $outcome->status());
        $this->assertSame('missing_expected_response', $outcome->reason);

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame('refused', $row->status);
        $this->assertSame('missing_expected_response', $row->refusal_reason);
    }

    #[Test]
    public function empty_string_expected_response_is_refused(): void
    {
        $envelope = $this->envelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: $this->wellFormedContent(),
            context: [],
            expectedResponse: '',
        );

        $outcome = $this->service()->send($envelope);

        $this->assertInstanceOf(MessageRefusal::class, $outcome);
        $this->assertSame('missing_expected_response', $outcome->reason);
    }

    #[Test]
    public function empty_context_array_is_not_refused_and_still_delivers(): void
    {
        $envelope = $this->envelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: $this->wellFormedContent(),
            context: [],
            expectedResponse: 'an acknowledgement',
        );

        $outcome = $this->service()->send($envelope);

        $this->assertSame('delivered', $outcome->status());

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame('delivered', $row->status);
        $this->assertNull($row->refusal_reason);
        $this->assertSame([], json_decode($row->context, true));
    }

    #[Test]
    public function refused_row_stamps_owner_conversation_and_run_id_identically_to_a_delivered_row(): void
    {
        $conversationId = (string) Str::uuid();
        $runId = (string) Str::uuid();

        Context::add('run_id', $runId);

        $envelope = $this->envelope(
            fromAgentId: null,
            toAgentId: $this->agentB->id,
            content: $this->wellFormedContent(),
            context: [],
            expectedResponse: 'an acknowledgement',
            conversationId: $conversationId,
        );

        $outcome = $this->service()->send($envelope);

        $this->assertInstanceOf(MessageRefusal::class, $outcome);

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame($this->user->id, $row->owner_user_id);
        $this->assertSame($conversationId, $row->conversation_id);
        $this->assertSame($runId, $row->run_id);

        Context::forget('run_id');
    }

    /**
     * research.md D6's fixed check order: structural validation runs
     * BEFORE the size bound (Phase 7 will add). A malformed envelope
     * (toAgentId: null) whose content ALSO independently exceeds
     * config('llm-client.messaging.max_message_bytes') must still refuse
     * with the correctly-reasoned missing_* status, not (once Phase 7
     * exists) rejected_oversized — proving the check order rather than
     * merely asserting it. At this phase the size check doesn't exist
     * yet, so this also simply confirms the malformed case refuses
     * correctly even when oversized.
     */
    #[Test]
    public function simultaneously_malformed_and_oversized_envelope_refuses_with_missing_reason_not_oversized(): void
    {
        $maxBytes = (int) config('llm-client.messaging.max_message_bytes', 65536);

        $oversizedContent = [new MessageContentPart(str_repeat('x', $maxBytes + 1024))];

        $envelope = $this->envelope(
            fromAgentId: $this->agentA->id,
            toAgentId: null,
            content: $oversizedContent,
            context: [],
            expectedResponse: 'an acknowledgement',
        );

        $outcome = $this->service()->send($envelope);

        $this->assertInstanceOf(MessageRefusal::class, $outcome);
        $this->assertSame('refused', $outcome->status());
        $this->assertSame('missing_recipient', $outcome->reason);
        $this->assertNotSame('rejected_oversized', $outcome->status());

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame('refused', $row->status);
        $this->assertSame('missing_recipient', $row->refusal_reason);
        // Structural validation stores content as given, not null — proves
        // no size-bound branch (Phase 7) ran or short-circuited storage.
        $this->assertNotNull($row->content);
    }
}
