<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Services\AgentMessageService;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageContentPart;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageEnvelope;
use ClarionApp\LlmClient\ValueObjects\Messaging\MessageRejection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 107-agent-message-protocol, Phase 7 (US5, T033), contracts §1 step 2 and
 * quickstart.md's test inventory: AgentMessageService::send()'s size-bound
 * step — checked SECOND, after structural validation and before recipient
 * resolution (research.md D6) — measures
 * strlen(json_encode(['content' => ..., 'context' => ...,
 * 'expected_response' => ...])) against
 * config('llm-client.messaging.max_message_bytes') and rejects outright
 * (never truncating) anything over the bound.
 *
 * Written before AgentMessageService::send() has any size-bound branch
 * (Phase 3-6 only implement delivery and structural validation) —
 * expected to FAIL red until T035's implementation.
 */
class AgentMessageServiceSizeBoundTest extends TestCase
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
        array $content,
        array $context = [],
        ?string $expectedResponse = 'an acknowledgement',
        ?string $conversationId = null,
    ): MessageEnvelope {
        return new MessageEnvelope(
            fromAgentId: $this->agentA->id,
            toAgentId: $this->agentB->id,
            content: $content,
            context: $context,
            expectedResponse: $expectedResponse,
            ownerUserId: $this->user->id,
            conversationId: $conversationId,
        );
    }

    #[Test]
    public function an_envelope_at_or_under_the_configured_cap_delivers_normally(): void
    {
        $maxBytes = (int) config('llm-client.messaging.max_message_bytes', 65536);

        // Comfortably under the cap: leave headroom for the JSON scaffolding
        // (keys, brackets, the expected_response string) so the measured
        // size_bytes genuinely lands at-or-under, not merely close to, the
        // configured bound.
        $content = [new MessageContentPart(str_repeat('a', $maxBytes - 1024))];

        $outcome = $this->service()->send($this->envelope($content));

        $this->assertSame('delivered', $outcome->status());

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame('delivered', $row->status);
        $this->assertNull($row->refusal_reason);
        $this->assertNotNull($row->content);
        $this->assertLessThanOrEqual($maxBytes, $row->size_bytes);
    }

    #[Test]
    public function an_envelope_over_the_configured_cap_is_rejected_with_the_measured_size(): void
    {
        $maxBytes = (int) config('llm-client.messaging.max_message_bytes', 65536);

        $content = [new MessageContentPart(str_repeat('x', $maxBytes + 1024))];

        $outcome = $this->service()->send($this->envelope($content));

        $this->assertInstanceOf(MessageRejection::class, $outcome);
        $this->assertSame('rejected_oversized', $outcome->status());
        $this->assertGreaterThan($maxBytes, $outcome->sizeBytes);

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame('rejected_oversized', $row->status);
        $this->assertNull($row->content);
        $this->assertNull($row->context);
        $this->assertNull($row->expected_response);
        $this->assertSame($outcome->sizeBytes, (int) $row->size_bytes);
        $this->assertGreaterThan($maxBytes, $row->size_bytes);
    }

    #[Test]
    public function an_oversized_row_never_stores_a_truncated_payload(): void
    {
        $maxBytes = (int) config('llm-client.messaging.max_message_bytes', 65536);

        $content = [new MessageContentPart(str_repeat('y', $maxBytes * 2))];

        $outcome = $this->service()->send($this->envelope($content));

        $this->assertSame('rejected_oversized', $outcome->status());

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        // Never a truncated string — content is null outright, not a
        // shortened or partial version of what was sent.
        $this->assertNull($row->content);
    }

    #[Test]
    public function a_rejected_row_stamps_owner_conversation_and_run_id_identically_to_a_delivered_row(): void
    {
        $conversationId = (string) Str::uuid();
        $runId = (string) Str::uuid();

        Context::add('run_id', $runId);

        $maxBytes = (int) config('llm-client.messaging.max_message_bytes', 65536);
        $content = [new MessageContentPart(str_repeat('z', $maxBytes + 1024))];

        $outcome = $this->service()->send($this->envelope($content, conversationId: $conversationId));

        $this->assertSame('rejected_oversized', $outcome->status());

        $row = DB::table('agent_messages')->where('id', $outcome->agentMessageId)->first();
        $this->assertNotNull($row);
        $this->assertSame($this->user->id, $row->owner_user_id);
        $this->assertSame($conversationId, $row->conversation_id);
        $this->assertSame($runId, $row->run_id);
        $this->assertSame($this->agentA->id, $row->from_agent_id);
        $this->assertSame($this->agentB->id, $row->to_agent_id);

        Context::forget('run_id');
    }
}
