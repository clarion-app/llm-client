<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every resolution of a question — answered, expired, withdrawn,
 * undeliverable — goes through AgentQuestionService's single private
 * compare-and-set transition. It is exercised here directly (through
 * reflection, as the narrowest seam) because every public resolving
 * method depends on exactly these properties:
 *
 *  - only a `pending` row can transition; the first caller wins (`true`),
 *    every later caller loses (`false`) and changes nothing;
 *  - the winner sets the state, `resolved_at`, clears the one-pending-per-
 *    conversation latch (`pending_conversation_id`) and applies its fields;
 *  - a run-continuing outcome marks the conversation processing in the same
 *    commit; a withdrawal leaves it untouched.
 */
class AgentQuestionServiceTransitionTest extends TestCase
{
    protected function tearDown(): void
    {
        if (Schema::hasTable('agent_questions')) {
            DB::table('agent_questions')->delete();
        }
        DB::table('conversations')->delete();

        parent::tearDown();
    }

    private function pendingQuestion(Conversation $conversation, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('agent_questions')->insert($overrides + [
            'id' => $id,
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'pending_conversation_id' => $conversation->id,
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
            'state' => 'pending',
            'asked_at' => $now,
            'expires_at' => $now->copy()->addMinutes(1440),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function transition(string $id, QuestionState $to, array $fields, bool $setProcessing): bool
    {
        $service = app(AgentQuestionService::class);
        $method = new \ReflectionMethod($service, 'transition');
        $method->setAccessible(true);

        return $method->invoke($service, $id, $to, $fields, $setProcessing);
    }

    private function row(string $id): object
    {
        return DB::table('agent_questions')->where('id', $id)->first();
    }

    #[Test]
    public function the_first_caller_wins_and_resolves_the_row(): void
    {
        $conversation = Conversation::factory()->create(['is_processing' => false]);
        $id = $this->pendingQuestion($conversation);

        $won = $this->transition($id, QuestionState::Answered, ['answer_raw' => '2'], true);

        $this->assertTrue($won);
        $row = $this->row($id);
        $this->assertSame('answered', $row->state);
        $this->assertNotNull($row->resolved_at);
        $this->assertNull($row->pending_conversation_id, 'the winner releases the one-pending-per-conversation latch');
        $this->assertSame('2', $row->answer_raw);
    }

    #[Test]
    public function a_second_caller_on_the_same_row_loses_and_changes_nothing(): void
    {
        $conversation = Conversation::factory()->create(['is_processing' => false]);
        $id = $this->pendingQuestion($conversation);

        $this->assertTrue($this->transition($id, QuestionState::Answered, ['answer_raw' => '2'], true));
        $before = (array) $this->row($id);

        // refresh() first: the in-memory model still holds the pre-transition
        // value, so update() alone would see no change and write nothing.
        $conversation->refresh()->update(['is_processing' => false]);
        $lost = $this->transition($id, QuestionState::Expired, [], true);

        $this->assertFalse($lost);
        $this->assertSame($before, (array) $this->row($id), 'a losing transition must not touch the row');
        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'a losing transition must not touch the conversation');
    }

    #[Test]
    public function terminal_states_never_transition_again(): void
    {
        foreach (['answered', 'expired', 'withdrawn', 'undeliverable'] as $terminal) {
            $conversation = Conversation::factory()->create(['is_processing' => false]);
            $id = $this->pendingQuestion($conversation, [
                'state' => $terminal,
                'pending_conversation_id' => null,
                'resolved_at' => now()->subMinute(),
            ]);
            $before = (array) $this->row($id);

            foreach ([QuestionState::Answered, QuestionState::Expired, QuestionState::Withdrawn, QuestionState::Undeliverable] as $to) {
                $this->assertFalse(
                    $this->transition($id, $to, [], true),
                    "a {$terminal} question must never become {$to->value}",
                );
            }

            $this->assertSame($before, (array) $this->row($id));
            $this->assertFalse((bool) $conversation->fresh()->is_processing);
        }
    }

    #[Test]
    public function run_continuing_outcomes_mark_the_conversation_processing_in_the_winning_commit(): void
    {
        foreach ([QuestionState::Answered, QuestionState::Expired, QuestionState::Undeliverable] as $to) {
            $this->assertTrue($to->continuesRun(), "{$to->value} continues the run");

            $conversation = Conversation::factory()->create(['is_processing' => false]);
            $id = $this->pendingQuestion($conversation);

            $this->assertTrue($this->transition($id, $to, [], true));

            $this->assertSame($to->value, $this->row($id)->state);
            $this->assertTrue((bool) $conversation->fresh()->is_processing, "{$to->value} must set is_processing in the winning commit");
        }
    }

    #[Test]
    public function withdrawal_leaves_the_conversation_not_processing(): void
    {
        $this->assertFalse(QuestionState::Withdrawn->continuesRun());

        $conversation = Conversation::factory()->create(['is_processing' => false]);
        $id = $this->pendingQuestion($conversation);

        $this->assertTrue($this->transition($id, QuestionState::Withdrawn, [], false));

        $row = $this->row($id);
        $this->assertSame('withdrawn', $row->state);
        $this->assertNull($row->pending_conversation_id);
        $this->assertNotNull($row->resolved_at);
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
    }

    #[Test]
    public function transition_only_touches_its_own_row(): void
    {
        $conversation = Conversation::factory()->create(['is_processing' => false]);
        $other = Conversation::factory()->create(['is_processing' => false]);
        $id = $this->pendingQuestion($conversation);
        $otherId = $this->pendingQuestion($other);

        $this->assertTrue($this->transition($id, QuestionState::Undeliverable, ['delivery_failure_reason' => 'telegram account not linked'], true));

        $this->assertSame('telegram account not linked', $this->row($id)->delivery_failure_reason);
        $this->assertSame('pending', $this->row($otherId)->state);
        $this->assertSame($other->id, $this->row($otherId)->pending_conversation_id);
        $this->assertFalse((bool) $other->fresh()->is_processing);
    }

    #[Test]
    public function every_state_reports_whether_it_is_terminal(): void
    {
        $this->assertFalse(QuestionState::Pending->isTerminal());
        $this->assertFalse(QuestionState::Pending->continuesRun());

        foreach ([QuestionState::Answered, QuestionState::Expired, QuestionState::Withdrawn, QuestionState::Undeliverable] as $state) {
            $this->assertTrue($state->isTerminal(), "{$state->value} is terminal");
        }
    }
}
