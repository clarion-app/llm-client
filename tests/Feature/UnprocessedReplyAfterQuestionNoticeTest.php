<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * When a streamed run finishes, a reply the user typed while it ran is
 * picked up and answered. A question notice the system wrote after that
 * reply — above all the one telling the user their reply came after a
 * question expired on its assumption, which is posted right after the reply
 * itself — is not the agent replying to anyone, so it must never hide the
 * reply.
 */
class UnprocessedReplyAfterQuestionNoticeTest extends TestCase
{
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversation = Conversation::factory()->create(['is_processing' => false]);
    }

    private function message(string $role, string $content, string $at, ?array $toolData = null): void
    {
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $this->conversation->id,
            'role' => $role,
            'user' => $role === 'user' ? 'Tim' : 'Clarion',
            'content' => $content,
            'responseTime' => 0,
            'tool_data' => $toolData === null ? null : json_encode($toolData),
            'created_at' => $at,
            'updated_at' => $at,
            'deleted_at' => null,
        ]);
    }

    private function checkForUnprocessedMessages(): void
    {
        $method = new \ReflectionMethod(AgentLoopStreamHandler::class, 'checkForUnprocessedMessages');
        $method->invoke(new AgentLoopStreamHandler(), $this->conversation);
    }

    #[Test]
    public function a_reply_followed_by_its_late_reply_notice_is_still_picked_up(): void
    {
        $this->message('assistant', 'Continuing with the backend.', '2026-10-12 10:01:05');
        $this->message('user', 'No, start with the frontend.', '2026-10-12 10:01:30');
        $this->message('assistant', 'Your reply arrived after the question "Which part?" had expired. …', '2026-10-12 10:01:31', [
            'kind' => AgentQuestionService::KIND_NOTICE,
            'question_id' => (string) Str::uuid(),
            'state' => 'expired_assumed',
            'notice' => 'late_reply',
            'delivered' => true,
        ]);

        $loop = $this->mock(AgentLoopService::class);
        $loop->shouldReceive('start')->once()->withArgs(fn ($conversation) => $conversation->id === $this->conversation->id);

        $this->checkForUnprocessedMessages();
    }

    #[Test]
    public function a_reply_followed_by_an_expiry_notice_is_still_picked_up(): void
    {
        $this->message('assistant', 'Working on it.', '2026-10-12 10:00:00');
        $this->message('user', 'Also update the docs.', '2026-10-12 10:00:30');
        $this->message('assistant', 'No answer to "Which part?" was received by the deadline …', '2026-10-12 10:01:00', [
            'kind' => AgentQuestionService::KIND_NOTICE,
            'question_id' => (string) Str::uuid(),
            'state' => 'expired_assumed',
        ]);

        $loop = $this->mock(AgentLoopService::class);
        $loop->shouldReceive('start')->once();

        $this->checkForUnprocessedMessages();
    }

    #[Test]
    public function a_reply_the_agent_already_answered_is_not_picked_up_again(): void
    {
        $this->message('user', 'No, start with the frontend.', '2026-10-12 10:01:30');
        $this->message('assistant', 'Your reply arrived after the question "Which part?" had expired. …', '2026-10-12 10:01:31', [
            'kind' => AgentQuestionService::KIND_NOTICE,
            'notice' => 'late_reply',
        ]);
        $this->message('assistant', 'Switching to the frontend.', '2026-10-12 10:02:00');

        $loop = $this->mock(AgentLoopService::class);
        $loop->shouldReceive('start')->never();

        $this->checkForUnprocessedMessages();
    }

    #[Test]
    public function only_notices_after_the_reply_still_count_as_no_reply(): void
    {
        // Nothing but notices from the system: the conversation has no
        // reply from the agent at all, as before.
        $this->message('user', 'Hello', '2026-10-12 10:00:00');
        $this->message('assistant', 'A notice', '2026-10-12 10:00:01', ['kind' => AgentQuestionService::KIND_NOTICE]);

        $loop = $this->mock(AgentLoopService::class);
        $loop->shouldReceive('start')->never();

        $this->checkForUnprocessedMessages();
    }
}
