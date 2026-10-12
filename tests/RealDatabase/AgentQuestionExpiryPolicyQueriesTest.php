<?php

namespace Tests\RealDatabase;

use ClarionApp\LlmClient\Commands\PurgeExpiredRunTracesCommand;
use ClarionApp\LlmClient\Commands\ResolveAbandonedRunsCommand;
use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\Controllers\AgentController;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The queries and writes that decide what happens to an unanswered
 * question, on the engine production runs.
 *
 * SQLite accepts some SQL MariaDB rejects (and the reverse), and drops
 * nothing a TIMESTAMP(6) column would: only here can it be seen that the
 * stop keeps the fraction of a second it happened at, that the late-reply
 * claim is a working compare-and-set, that each "is this question still
 * waiting?" query — the abandoned-run exemption with its grace, the purge
 * exemption, the run summaries' two flags, and the two lookups a reply
 * without a conversation id goes through — selects exactly the rows it
 * should, and that a notice records its delivery attempt.
 */
#[Group('real-db')]
class AgentQuestionExpiryPolicyQueriesTest extends RealDatabaseTestCase
{
    protected array $seedTables = ['agent_questions', 'messages', 'conversations', 'agent_runs'];

    private const NOW = '2026-10-12 10:01:00.123456';

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = (string) Str::uuid();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function conversation(string $channel = 'web', array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('conversations')->insert($overrides + [
            'id' => $id,
            'user_id' => $this->userId,
            'model' => 'test-model',
            'character' => 'Clarion',
            'channel' => $channel,
            'is_processing' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function agentRun(string $conversationId): string
    {
        $id = (string) Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $id,
            'kind' => 'interactive',
            'user_id' => $this->userId,
            'conversation_id' => $conversationId,
            'started_at' => now()->subDays(400)->format('Y-m-d H:i:s.u'),
        ]);

        return $id;
    }

    /** A question row; waiting states hold their conversation's slot. */
    private function question(string $conversationId, string $state, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $waiting = in_array($state, ['pending', 'stopped'], true);

        DB::table('agent_questions')->insert($overrides + [
            'id' => $id,
            'conversation_id' => $conversationId,
            'pending_conversation_id' => $waiting ? $conversationId : null,
            'user_id' => $this->userId,
            'paused_message_id' => (string) Str::uuid(),
            'tool_call_id' => 'call_ask',
            'channel' => 'web',
            'resume_mode' => 'stream',
            'question' => 'Which test framework should I use?',
            'choices' => json_encode(['PHPUnit', 'Pest', 'Codeception']),
            'state' => $state,
            'unanswered_policy' => 'stop',
            'effective_wait_minutes' => 60,
            'asked_at' => now()->subHours(2),
            'expires_at' => now()->subHour(),
            'resolved_at' => $waiting ? null : now()->subMinutes(30),
            'resumed_at' => $waiting ? null : now()->subMinutes(30),
            'stopped_at' => $state === 'stopped' ? now()->subMinutes(30) : null,
        ]);

        return $id;
    }

    private function callPrivate(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    #[Test]
    public function the_stop_keeps_its_fraction_of_a_second_and_its_slot(): void
    {
        $this->assertReady();

        $conversation = $this->conversation();
        $id = $this->question($conversation, 'pending', ['expires_at' => now()->subMinute()]);

        $service = app(AgentQuestionService::class);
        $this->assertSame(QuestionState::Stopped, $service->expireWithPolicy(AgentQuestion::findOrFail($id)));
        $this->assertNull($service->expireWithPolicy(AgentQuestion::findOrFail($id)), 'a second stop loses');

        $row = DB::table('agent_questions')->where('id', $id)->first();
        $this->assertSame('stopped', $row->state);
        $this->assertSame('2026-10-12 10:01:00.123456', (string) $row->stopped_at, 'stored with its fraction of a second');
        $this->assertSame($conversation, $row->pending_conversation_id, 'the slot is kept');
        $this->assertNull($row->resolved_at);

        $notice = Message::where('conversation_id', $conversation)->get()
            ->first(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_NOTICE);
        $this->assertNotNull($notice, 'the stop notice is written');
        $this->assertSame('stopped', $notice->tool_data['state']);
        $this->assertArrayHasKey('delivered', $notice->fresh()->tool_data, 'the delivery attempt is recorded');

        // A second stop of the same row changes nothing.
        $this->assertSame('2026-10-12 10:01:00.123456', (string) DB::table('agent_questions')->where('id', $id)->value('stopped_at'));
    }

    #[Test]
    public function the_late_reply_claim_is_taken_once_with_its_fraction_of_a_second(): void
    {
        $this->assertReady();

        $id = $this->question($this->conversation(), 'expired_assumed', [
            'unanswered_policy' => 'proceed_on_assumption',
            'assumed_answer_raw' => '1',
            'assumed_answer_selected' => json_encode([['number' => 1, 'text' => 'PHPUnit']]),
        ]);
        $stopped = $this->question($this->conversation(), 'stopped');

        $service = app(AgentQuestionService::class);
        $this->assertFalse($service->claimLateReplyNotice($stopped), 'only a question that proceeded on its assumption owes one');
        $this->assertTrue($service->claimLateReplyNotice($id));
        $this->assertFalse($service->claimLateReplyNotice($id), 'claimed once');
        $this->assertSame(
            '2026-10-12 10:01:00.123456',
            (string) DB::table('agent_questions')->where('id', $id)->value('late_reply_noticed_at'),
        );
    }

    #[Test]
    public function each_still_waiting_query_selects_exactly_the_waiting_rows(): void
    {
        $this->assertReady();

        $runs = [];
        $cases = [
            'pending, deadline ahead' => ['pending', ['expires_at' => now()->addHour()]],
            'pending, deadline 5 minutes ago' => ['pending', ['expires_at' => now()->subMinutes(5)]],
            'pending, deadline 20 minutes ago' => ['pending', ['expires_at' => now()->subMinutes(20)]],
            'stopped a year ago' => ['stopped', ['stopped_at' => now()->subYear(), 'expires_at' => now()->subYear()]],
            'expired_assumed' => ['expired_assumed', ['unanswered_policy' => 'proceed_on_assumption']],
            'answered late' => ['answered', ['stopped_at' => now()->subDay()]],
            'answered, resume queued' => ['answered', ['resolved_at' => now()->subMinutes(2), 'resumed_at' => null]],
            'answered, resumed' => ['answered', ['resolved_at' => now()->subMinutes(2), 'resumed_at' => now()->subMinutes(2)]],
        ];
        foreach ($cases as $name => [$state, $overrides]) {
            $conversation = $this->conversation();
            $runs[$name] = $this->agentRun($conversation);
            $this->question($conversation, $state, $overrides + ['run_id' => $runs[$name]]);
        }
        $all = array_values($runs);
        $only = fn (string ...$names) => array_map(fn (string $n) => $runs[$n], $names);

        // The abandoned-run exemption: a stopped question always, a pending
        // one until ten minutes past its deadline, a resolved one whose run
        // has not resumed yet while within the abandonment threshold.
        $exempt = $this->callPrivate(app(ResolveAbandonedRunsCommand::class), 'getPendingQuestionRunIds', [now()->subMinutes(60)]);
        $this->assertEqualsCanonicalizing($only('pending, deadline ahead', 'pending, deadline 5 minutes ago', 'stopped a year ago', 'answered, resume queued'), $exempt);

        // The purge keeps every run a waiting question names, however old,
        // and one whose resume is still to run.
        $purgeable = $this->callPrivate(app(PurgeExpiredRunTracesCommand::class), 'expiredRunsQuery', [now()->subDays(90)])
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertEqualsCanonicalizing($only('expired_assumed', 'answered late', 'answered, resumed'), $purgeable);

        // The run summaries' flags.
        $query = app(RunTraceQuery::class);
        $this->assertEqualsCanonicalizing(
            $only('pending, deadline ahead', 'pending, deadline 5 minutes ago', 'pending, deadline 20 minutes ago', 'stopped a year ago'),
            $query->runIdsAwaitingUser($all),
        );
        $this->assertEqualsCanonicalizing($only('stopped a year ago'), $query->runIdsStoppedOnQuestion($all));
    }

    #[Test]
    public function a_reply_without_a_conversation_finds_the_waiting_question_and_the_owed_notice(): void
    {
        $this->assertReady();
        $controller = app(AgentController::class);

        // A stopped question on the channel is found for a late answer.
        $stoppedIn = $this->conversation('telegram');
        $this->question($stoppedIn, 'stopped', ['channel' => 'telegram']);
        $found = $this->callPrivate($controller, 'conversationAwaitingAnswer', [$this->userId, 'telegram']);
        $this->assertInstanceOf(Conversation::class, $found);
        $this->assertSame($stoppedIn, $found->id);
        $this->assertNull($this->callPrivate($controller, 'conversationAwaitingAnswer', [$this->userId, 'web']), 'only on its channel');
        $this->assertNull($this->callPrivate($controller, 'conversationAwaitingAnswer', [(string) Str::uuid(), 'telegram']), 'only for its user');

        // The newest question that proceeded on its assumption, un-noticed,
        // in an open conversation, is owed the notice; a superseded one is
        // not.
        $assumedIn = $this->conversation('telegram', ['updated_at' => now()->subDays(2)]);
        $this->question($assumedIn, 'expired_assumed', ['channel' => 'telegram', 'unanswered_policy' => 'proceed_on_assumption', 'asked_at' => now()->subHours(5)]);
        $superseded = $this->conversation('telegram');
        $this->question($superseded, 'expired_assumed', ['channel' => 'telegram', 'unanswered_policy' => 'proceed_on_assumption', 'asked_at' => now()->subHours(3)]);
        $this->question($superseded, 'answered', ['channel' => 'telegram', 'asked_at' => now()->subHours(2)]);
        $deleted = $this->conversation('telegram', ['deleted_at' => now()->subHour()]);
        $this->question($deleted, 'expired_assumed', ['channel' => 'telegram', 'unanswered_policy' => 'proceed_on_assumption', 'asked_at' => now()->subHour()]);
        $ended = $this->conversation('telegram', ['ended_at' => now()->subHours(5)]);
        $endedQuestion = $this->question($ended, 'expired_assumed', ['channel' => 'telegram', 'unanswered_policy' => 'proceed_on_assumption', 'asked_at' => now()->subHours(6)]);

        $owed = $this->callPrivate($controller, 'conversationOwedLateReplyNotice', [$this->userId, 'telegram']);
        $this->assertInstanceOf(Conversation::class, $owed);
        $this->assertSame($assumedIn, $owed->id, 'the newest un-noticed, unsuperseded question in a conversation not deleted');
        $this->assertNull($this->callPrivate($controller, 'conversationOwedLateReplyNotice', [(string) Str::uuid(), 'telegram']));

        // An ended conversation is still owed its notice (the idle timeout
        // ends it long before a late reply usually comes).
        DB::table('agent_questions')->where('conversation_id', $assumedIn)->update(['late_reply_noticed_at' => now()->format('Y-m-d H:i:s.u')]);
        $owed = $this->callPrivate($controller, 'conversationOwedLateReplyNotice', [$this->userId, 'telegram']);
        $this->assertInstanceOf(Conversation::class, $owed);
        $this->assertSame($ended, $owed->id, 'an ended conversation is still told');
        $this->assertNotNull($endedQuestion);
    }

    #[Test]
    public function an_undelivered_notice_records_its_bounded_reason(): void
    {
        $this->assertReady();

        // A channel that fails with the longest reason: the record keeps
        // it whole, cut to its bound.
        app(ConversationChannelRegistry::class)->register(new class implements ConversationChannelDelivery {
            public function channel(): string
            {
                return 'failing';
            }

            public function deliver(Conversation $conversation, string $text): DeliveryResult
            {
                return DeliveryResult::failed(str_repeat('é', 3000));
            }
        });
        $conversation = $this->conversation('failing');
        $id = $this->question($conversation, 'pending', ['expires_at' => now()->subMinute()]);

        $this->assertSame(QuestionState::Stopped, app(AgentQuestionService::class)->expireWithPolicy(AgentQuestion::findOrFail($id)));

        $notice = Message::where('conversation_id', $conversation)->get()
            ->first(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_NOTICE);
        $this->assertNotNull($notice);
        $toolData = $notice->fresh()->tool_data;
        $this->assertFalse($toolData['delivered']);
        $this->assertSame(512, mb_strlen($toolData['delivery_failure_reason']), 'cut to its bound and stored whole');
    }
}
