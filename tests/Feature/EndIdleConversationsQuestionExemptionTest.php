<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\ConversationLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The idle-session sweep never ends a conversation that is waiting on its
 * user's answer.
 *
 * A pending question leaves the conversation not processing (the reply has
 * to reach the answer path) and quiet for as long as the user takes —
 * exactly what the idle sweep looks for. Ending it would fire
 * ConversationEnded mid-task and clear the agent's short-term memory under
 * a run that is about to resume. The idle timeout is also never a
 * cancellation: it does not withdraw the question.
 */
class EndIdleConversationsQuestionExemptionTest extends TestCase
{
    protected function tearDown(): void
    {
        if (Schema::hasTable('agent_questions')) {
            DB::table('agent_questions')->delete();
        }
        DB::table('messages')->delete();
        DB::table('conversations')->delete();

        parent::tearDown();
    }

    private function idleConversation(): Conversation
    {
        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $conversation->forceFill(['updated_at' => now()->subMinutes(90)])->save();

        return $conversation;
    }

    private function question(Conversation $conversation, string $state = 'pending'): string
    {
        $id = (string) Str::uuid();
        $askedAt = now()->subMinutes(90);
        $pending = $state === 'pending';

        DB::table('agent_questions')->insert([
            'id' => $id,
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'pending_conversation_id' => $pending ? $conversation->id : null,
            'run_id' => null,
            'step_id' => null,
            'action_id' => null,
            'paused_message_id' => (string) Str::uuid(),
            'display_message_id' => null,
            'tool_call_id' => 'call_ask',
            'channel' => 'web',
            'resume_mode' => 'stream',
            'question' => 'Which part should I change first?',
            'choices' => json_encode(['Backend', 'Frontend', 'Docs']),
            'allow_multiple' => false,
            'state' => $state,
            'asked_at' => $askedAt,
            'expires_at' => $askedAt->copy()->addMinutes(1440),
            'resolved_at' => $pending ? null : now()->subMinutes(60),
            'created_at' => $askedAt,
            'updated_at' => $askedAt,
        ]);

        return $id;
    }

    #[Test]
    public function an_idle_conversation_with_a_pending_question_is_not_ended(): void
    {
        Event::fake([ConversationEnded::class]);

        $waiting = $this->idleConversation();
        $questionId = $this->question($waiting);

        $ended = $this->app->make(ConversationLifecycleService::class)->endIdleConversations(30);

        $this->assertSame(0, $ended);
        $this->assertNull($waiting->fresh()->ended_at, 'the session stays open under a pending question');
        Event::assertNotDispatched(ConversationEnded::class);

        // The idle timeout is not a cancellation.
        $row = DB::table('agent_questions')->where('id', $questionId)->first();
        $this->assertSame('pending', $row->state, 'the question is not withdrawn');
        $this->assertSame($waiting->id, $row->pending_conversation_id);
        $this->assertNull($row->resolved_at);
        $this->assertSame(
            0,
            DB::table('messages')->where('conversation_id', $waiting->id)->count(),
            'no withdrawal notice is shown',
        );
    }

    #[Test]
    public function idle_conversations_without_a_pending_question_still_end(): void
    {
        Event::fake([ConversationEnded::class]);

        $waiting = $this->idleConversation();
        $this->question($waiting);

        $plain = $this->idleConversation();

        $resolved = $this->idleConversation();
        $this->question($resolved, 'expired');

        $ended = $this->app->make(ConversationLifecycleService::class)->endIdleConversations(30);

        $this->assertSame(2, $ended);
        $this->assertNull($waiting->fresh()->ended_at);
        $this->assertNotNull($plain->fresh()->ended_at, 'an ordinary idle session ends as before');
        $this->assertNotNull($resolved->fresh()->ended_at, 'a resolved question no longer holds the session open');
        Event::assertDispatchedTimes(ConversationEnded::class, 2);
    }

    #[Test]
    public function the_idle_command_skips_a_conversation_with_a_pending_question(): void
    {
        Event::fake([ConversationEnded::class]);

        $waiting = $this->idleConversation();
        $this->question($waiting);
        $plain = $this->idleConversation();

        $this->artisan('llm-client:end-idle-conversations', ['--minutes' => 30])->assertExitCode(0);

        $this->assertNull($waiting->fresh()->ended_at);
        $this->assertNotNull($plain->fresh()->ended_at);
        Event::assertDispatchedTimes(ConversationEnded::class, 1);
    }

    #[Test]
    public function the_session_can_end_once_the_question_is_resolved(): void
    {
        Event::fake([ConversationEnded::class]);

        $waiting = $this->idleConversation();
        $questionId = $this->question($waiting);
        $lifecycle = $this->app->make(ConversationLifecycleService::class);

        $this->assertSame(0, $lifecycle->endIdleConversations(30));

        DB::table('agent_questions')->where('id', $questionId)->update([
            'state' => 'expired',
            'pending_conversation_id' => null,
            'resolved_at' => now(),
        ]);

        $this->assertSame(1, $lifecycle->endIdleConversations(30));
        $this->assertNotNull($waiting->fresh()->ended_at);
    }
}
