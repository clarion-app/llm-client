<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\UserReachability;
use ClarionApp\LlmClient\ValueObjects\QuestionRefusal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * Who can be asked: one rule for an agent's question and a task's ceiling
 * prompt.
 *
 * The rule was AgentLoopService::askReachabilityRefusal(). A task that
 * reaches its budget ceiling asks its user what to do exactly when an agent
 * could have asked that user a question, so the rule moves to
 * UserReachability unchanged and both callers share it. Each case below
 * pins the refusal the rule gave before it moved — code and reason, byte
 * for byte — and checks that the agent loop still answers identically.
 */
class UserReachabilityTest extends TestCase
{
    private const NO_USER = 'This task has no user to ask.';
    private const UNATTENDED = 'This is an unattended run; no user is present to answer.';
    private const HELPER = 'Delegated helpers cannot pause to ask the user; return the open question to your caller in your result.';
    private const ALREADY_PENDING = "A question is already waiting for the user's answer in this conversation. Wait for it to be resolved before asking another.";

    protected function tearDown(): void
    {
        DB::table('agent_questions')->delete();
        DB::table('agent_delegations')->delete();
        DB::table('conversations')->delete();

        parent::tearDown();
    }

    private function conversation(array $attributes = []): Conversation
    {
        return Conversation::create($attributes + [
            'user_id' => (string) Str::uuid(),
            'channel' => 'web',
            'title' => 'Reachability',
        ]);
    }

    private function delegate(Conversation $parent, Conversation $helper): void
    {
        DB::table('agent_delegations')->insert([
            'id' => (string) Str::uuid(),
            'parent_conversation_id' => $parent->id,
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => $helper->id,
            'owner_user_id' => (string) $parent->user_id,
            'task' => 'Help.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    private function pendingQuestionOn(Conversation $conversation): void
    {
        DB::table('agent_questions')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'user_id' => (string) $conversation->user_id,
            'pending_conversation_id' => $conversation->id,
            'paused_message_id' => (string) Str::uuid(),
            'tool_call_id' => 'call_ask',
            'channel' => 'web',
            'resume_mode' => 'stream',
            'question' => 'Which one?',
            'choices' => json_encode(['A', 'B']),
            'state' => 'pending',
            'asked_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
    }

    /** What the agent loop's own rule says, through its private method. */
    private function agentLoopRefusal(Conversation $conversation, bool $unattended): ?QuestionRefusal
    {
        $method = new \ReflectionMethod(AgentLoopService::class, 'askReachabilityRefusal');

        return $method->invoke(app(AgentLoopService::class), $conversation, $unattended);
    }

    private function assertRefusal(?QuestionRefusal $expected, Conversation $conversation, bool $unattended = false): void
    {
        $actual = app(UserReachability::class)->refusal($conversation, $unattended);

        if ($expected === null) {
            $this->assertNull($actual, 'reachable');
        } else {
            $this->assertNotNull($actual, 'refused');
            $this->assertSame($expected->code, $actual->code);
            $this->assertSame($expected->reason, $actual->reason);
        }

        $fromLoop = $this->agentLoopRefusal($conversation, $unattended);
        $this->assertSame($actual?->code, $fromLoop?->code, 'the agent loop gives the same answer');
        $this->assertSame($actual?->reason, $fromLoop?->reason);
    }

    #[Test]
    public function a_conversation_with_no_user_cannot_be_asked(): void
    {
        $this->assertRefusal(new QuestionRefusal('no_reachable_user', self::NO_USER), $this->conversation(['user_id' => null]));
    }

    #[Test]
    public function a_conversation_with_an_empty_user_cannot_be_asked(): void
    {
        $conversation = $this->conversation();
        $conversation->user_id = '';

        $this->assertRefusal(new QuestionRefusal('no_reachable_user', self::NO_USER), $conversation);
    }

    #[Test]
    public function an_unattended_run_cannot_ask(): void
    {
        $this->assertRefusal(new QuestionRefusal('no_reachable_user', self::UNATTENDED), $this->conversation(), true);
    }

    #[Test]
    public function the_missing_user_is_reported_before_unattended(): void
    {
        $this->assertRefusal(new QuestionRefusal('no_reachable_user', self::NO_USER), $this->conversation(['user_id' => null]), true);
    }

    #[Test]
    public function a_helper_cannot_ask(): void
    {
        $root = $this->conversation();
        $helper = $this->conversation(['user_id' => $root->user_id, 'channel' => 'agent-delegation']);
        $this->delegate($root, $helper);

        $this->assertRefusal(new QuestionRefusal('no_reachable_user', self::HELPER), $helper);
    }

    #[Test]
    public function a_helper_with_no_delegation_row_cannot_ask(): void
    {
        $this->assertRefusal(new QuestionRefusal('no_reachable_user', self::HELPER), $this->conversation(['channel' => 'agent-delegation']));
    }

    #[Test]
    public function a_helper_whose_chain_root_is_waiting_on_a_question_is_told_so(): void
    {
        $root = $this->conversation();
        $middle = $this->conversation(['user_id' => $root->user_id, 'channel' => 'agent-delegation']);
        $helper = $this->conversation(['user_id' => $root->user_id, 'channel' => 'agent-delegation']);
        $this->delegate($root, $middle);
        $this->delegate($middle, $helper);
        $this->pendingQuestionOn($root);

        $this->assertRefusal(new QuestionRefusal('question_already_pending', self::ALREADY_PENDING), $helper);
        $this->assertRefusal(new QuestionRefusal('question_already_pending', self::ALREADY_PENDING), $middle);
    }

    #[Test]
    public function an_unregistered_channel_cannot_be_asked(): void
    {
        foreach (['managed-task', 'scheduler', 'sequence-run', 'telegram'] as $channel) {
            $this->assertRefusal(
                new QuestionRefusal('no_reachable_user', "Questions cannot be delivered on the '{$channel}' channel."),
                $this->conversation(['channel' => $channel])
            );
        }
    }

    #[Test]
    public function a_web_conversation_with_a_user_can_be_asked(): void
    {
        $this->assertTrue(app(ConversationChannelRegistry::class)->has('web'), 'precondition: llm-client registers the web channel');

        $this->assertRefusal(null, $this->conversation());
        $this->assertRefusal(null, $this->conversation(['channel' => null]), false);
    }

    #[Test]
    public function a_registered_messaging_channel_can_be_asked(): void
    {
        app(ConversationChannelRegistry::class)->register(new RecordingChannelDelivery('telegram'));

        $this->assertRefusal(null, $this->conversation(['channel' => 'telegram']));
        $this->assertRefusal(new QuestionRefusal('no_reachable_user', self::UNATTENDED), $this->conversation(['channel' => 'telegram']), true);
    }
}
