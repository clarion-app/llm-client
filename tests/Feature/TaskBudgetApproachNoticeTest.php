<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetStanding;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * The user is told once, in the conversation, when a task's consumption
 * first crosses the approach threshold of either ceiling.
 *
 * The check runs at every step boundary the task budget already guards,
 * after the step has been allowed, on completed consumption: the steps
 * already finished (the step just claimed has not run yet) and the spend
 * already recorded. Each ceiling has its own latch, taken by one
 * conditional write, so however many workers cross at once exactly one of
 * them posts. The notice is written by the system — never left to the
 * agent — into the task's root conversation, where the user is, and then
 * delivered on that conversation's channel. A notice that cannot be
 * delivered, or even written, never holds up or stops the step. It is a
 * conversation-only message: the model never sees it, and it never hides a
 * reply the user typed just before it.
 */
class TaskBudgetApproachNoticeTest extends TestCase
{
    private RecordingChannelDelivery $web;

    private RecordingChannelDelivery $telegram;

    /** @var list<MessageLogged> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.cost.currency' => 'USD']);

        $this->web = new RecordingChannelDelivery('web');
        $this->telegram = new RecordingChannelDelivery('telegram');
        $registry = $this->app->make(ConversationChannelRegistry::class);
        $registry->register($this->web);
        $registry->register($this->telegram);

        Event::listen(MessageLogged::class, function (MessageLogged $logged) {
            $this->logs[] = $logged;
        });
    }

    protected function tearDown(): void
    {
        foreach (['task_budget_events', 'task_budgets', 'usage_records', 'messages', 'conversations'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    private function conversation(string $channel = 'web'): Conversation
    {
        return Conversation::create(['user_id' => (string) Str::uuid(), 'channel' => $channel, 'title' => 'Approach']);
    }

    /** @return array{0: Conversation, 1: TaskBudget} a conversation with a running task of the given ceilings */
    private function withTask(int $steps = 50, string $spend = '1.00', string $channel = 'web'): array
    {
        $conversation = $this->conversation($channel);
        $task = app(TaskBudgetService::class)->forNewRun($conversation, new StatedCeilings(maxSteps: $steps, maxSpend: $spend), false, null);
        $this->assertNotNull($task, 'precondition: the task opened');
        $this->assertSame('0.8000', (string) $task->approach_threshold, 'precondition: the default threshold');

        return [$conversation, $task];
    }

    private function row(TaskBudget $task): object
    {
        return DB::table('task_budgets')->where('id', $task->id)->first();
    }

    /** Pretend $n steps have already been taken. */
    private function stepsAlreadyUsed(TaskBudget $task, int $n): void
    {
        DB::table('task_budgets')->where('id', $task->id)->update(['steps_used' => $n]);
    }

    private function spend(TaskBudget $task, string $cost): void
    {
        DB::table('usage_records')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $task->root_conversation_id,
            'user_id' => (string) Str::uuid(),
            'attempt_group_id' => (string) Str::uuid(),
            'task_budget_id' => $task->id,
            'total_cost' => $cost,
            'created_at' => now(),
        ]);
    }

    /** Every approach notice written anywhere, oldest first. */
    private function notices()
    {
        return Message::query()->orderBy('created_at')->get()
            ->filter(fn (Message $m) => is_array($m->tool_data) && ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_NOTICE)
            ->values();
    }

    /** @return list<array<string, mixed>> the payloads of the task's approach_notice events */
    private function approachEvents(TaskBudget $task): array
    {
        return DB::table('task_budget_events')->where('task_budget_id', $task->id)->where('kind', 'approach_notice')->orderBy('created_at')
            ->pluck('payload')->map(fn ($payload) => json_decode((string) $payload, true))->all();
    }

    /** The notice text for the task's current standing, as of $completed completed steps. */
    private function expectedText(TaskBudget $task, int $completed, array $ceilings): string
    {
        $standing = app(TaskBudgetService::class)->standing(TaskBudget::findOrFail($task->id));
        $asOf = new TaskBudgetStanding(...array_merge(get_object_vars($standing), ['stepsUsed' => $completed]));

        return (new TaskBudgetText())->approachNotice($asOf, $ceilings);
    }

    private function assertAllowed($decision, string $message = ''): void
    {
        $this->assertTrue($decision->isAllow(), $message.($decision->isStop() ? " (stopped: {$decision->reason})" : ''));
    }

    #[Test]
    public function a_fifty_step_task_posts_exactly_one_notice_once_its_fortieth_step_has_completed(): void
    {
        [$conversation, $task] = $this->withTask(steps: 50);
        $gate = app(TaskBudgetGate::class);

        for ($claim = 1; $claim <= 40; $claim++) {
            $this->assertAllowed($gate->beforeStep($conversation, null), "claim {$claim}");
        }
        $this->assertCount(0, $this->notices(), 'nothing before the 40th step has completed (it is still running after the 40th claim)');
        $this->assertNull($this->row($task)->steps_approach_noticed_at);

        // The 41st claim: 40 steps completed = ceil(0.8 x 50).
        $this->assertAllowed($gate->beforeStep($conversation, null), 'claim 41: a notice never stops the step');
        $notices = $this->notices();
        $this->assertCount(1, $notices, 'one notice, at the boundary after the 40th step completed');
        $notice = $notices[0];
        $this->assertSame($conversation->id, $notice->conversation_id);
        $this->assertSame('assistant', $notice->role);
        $this->assertSame(['kind' => 'task_budget_notice', 'task_budget_id' => $task->id, 'notice' => 'approach'], $notice->tool_data);
        $this->assertSame($this->expectedText($task, 40, ['steps']), $notice->content);
        $this->assertStringContainsString('40 of 50 steps, 0.00 of 1.00 USD', $notice->content, 'the same figures as the standing: steps used and ceiling, spend used and ceiling');
        $this->assertSame([$notice->content], $this->web->textsFor($conversation->id), 'delivered once, on the conversation\'s channel');
        $this->assertNotNull($this->row($task)->steps_approach_noticed_at, 'the steps latch is taken');
        $this->assertNull($this->row($task)->spend_approach_noticed_at, 'the spend ceiling has its own latch, untouched');
        $this->assertSame([[
            'ceilings' => ['steps'],
            'message_id' => $notice->id,
            'delivered' => true,
            'delivery_failure_reason' => null,
        ]], $this->approachEvents($task));

        for ($claim = 42; $claim <= 50; $claim++) {
            $this->assertAllowed($gate->beforeStep($conversation, null), "claim {$claim}");
        }
        $this->assertCount(1, $this->notices(), 'no further notice for steps 41 to 49 under the same allowance');

        $this->assertTrue($gate->beforeStep($conversation, null)->isStop(), 'claim 51 is refused');
        $this->assertCount(1, $this->notices());
        $this->assertCount(1, $this->web->textsFor($conversation->id));
        $this->assertCount(1, $this->approachEvents($task));
    }

    #[Test]
    public function a_boundary_that_stops_posts_no_approach_notice(): void
    {
        // At the step ceiling, with the latch still open.
        [$atSteps, $stepsTask] = $this->withTask(steps: 50);
        $this->stepsAlreadyUsed($stepsTask, 50);
        $this->assertTrue(app(TaskBudgetGate::class)->beforeStep($atSteps, null)->isStop());

        // At the spend ceiling, with the latch still open.
        [$atSpend, $spendTask] = $this->withTask(spend: '1.00');
        $this->spend($spendTask, '1.0000000000');
        $this->assertTrue(app(TaskBudgetGate::class)->beforeStep($atSpend, null)->isStop());

        // A ceiling so small that the threshold step is the last one: no
        // notice before the pause.
        [$small, $smallTask] = $this->withTask(steps: 2);
        $gate = app(TaskBudgetGate::class);
        $this->assertAllowed($gate->beforeStep($small, null));
        $this->assertAllowed($gate->beforeStep($small, null));
        $this->assertTrue($gate->beforeStep($small, null)->isStop());

        $this->assertCount(0, $this->notices(), 'the report covers a stop; no approach notice is posted there');
        $this->assertSame([], $this->web->deliveries);
        foreach ([$stepsTask, $spendTask, $smallTask] as $task) {
            $this->assertNull($this->row($task)->steps_approach_noticed_at);
            $this->assertNull($this->row($task)->spend_approach_noticed_at);
            $this->assertSame([], $this->approachEvents($task));
        }
    }

    #[Test]
    public function spend_crossing_on_its_own_posts_one_notice_naming_the_spend_ceiling(): void
    {
        [$conversation, $task] = $this->withTask(steps: 50, spend: '1.00');
        $gate = app(TaskBudgetGate::class);

        $this->assertAllowed($gate->beforeStep($conversation, null));
        $this->spend($task, '0.7900000000');
        $this->assertAllowed($gate->beforeStep($conversation, null));
        $this->assertCount(0, $this->notices(), 'below 80% of the spend ceiling');

        $this->spend($task, '0.0100000000');
        $this->assertAllowed($gate->beforeStep($conversation, null), 'recorded spend 0.80 of 1.00: allowed, and crossing');
        $notices = $this->notices();
        $this->assertCount(1, $notices);
        $this->assertSame($this->expectedText($task, 2, ['spend']), $notices[0]->content);
        $this->assertStringContainsString("'/budget spend=2.00'", $notices[0]->content, 'it names the spend ceiling only');
        $this->assertStringNotContainsString('steps=', $notices[0]->content);
        $this->assertNotNull($this->row($task)->spend_approach_noticed_at);
        $this->assertNull($this->row($task)->steps_approach_noticed_at);
        $this->assertSame(['spend'], $this->approachEvents($task)[0]['ceilings']);

        $this->spend($task, '0.1000000000');
        $this->assertAllowed($gate->beforeStep($conversation, null));
        $this->assertAllowed($gate->beforeStep($conversation, null));
        $this->assertCount(1, $this->notices(), 'once per ceiling per allowance');
    }

    #[Test]
    public function both_ceilings_crossing_at_once_post_one_notice_naming_both(): void
    {
        [$conversation, $task] = $this->withTask(steps: 50, spend: '1.00');
        $this->stepsAlreadyUsed($task, 40);
        $this->spend($task, '0.8500000000');

        $this->assertAllowed(app(TaskBudgetGate::class)->beforeStep($conversation, null));

        $notices = $this->notices();
        $this->assertCount(1, $notices, 'one notice, not one per ceiling');
        $this->assertSame($this->expectedText($task, 40, ['steps', 'spend']), $notices[0]->content);
        $this->assertStringContainsString("'/budget steps=100 spend=2.00'", $notices[0]->content);
        $this->assertNotNull($this->row($task)->steps_approach_noticed_at);
        $this->assertNotNull($this->row($task)->spend_approach_noticed_at);
        $events = $this->approachEvents($task);
        $this->assertCount(1, $events);
        $this->assertSame(['steps', 'spend'], $events[0]['ceilings']);
        $this->assertCount(1, $this->web->textsFor($conversation->id));
    }

    #[Test]
    public function a_helpers_crossing_claim_posts_the_notice_in_the_root_conversation(): void
    {
        [$root, $task] = $this->withTask(steps: 50, channel: 'telegram');
        $this->stepsAlreadyUsed($task, 40);
        $helper = Conversation::create([
            'user_id' => $root->user_id,
            'channel' => 'agent-delegation',
            'title' => 'Helper',
            'active_task_budget_id' => $task->id,
        ]);

        $this->assertAllowed(app(TaskBudgetGate::class)->beforeStep($helper, null), 'the helper\'s step');

        $notices = $this->notices();
        $this->assertCount(1, $notices);
        $this->assertSame($root->id, $notices[0]->conversation_id, 'where the user is');
        $this->assertSame(0, Message::where('conversation_id', $helper->id)->count(), 'nothing in the helper\'s conversation');
        $this->assertSame([$notices[0]->content], $this->telegram->textsFor($root->id), 'delivered on the root conversation\'s channel');
        $this->assertSame([], $this->web->deliveries);
    }

    #[Test]
    public function the_notice_is_delivered_only_after_it_has_been_written(): void
    {
        [$conversation, $task] = $this->withTask(steps: 50, channel: 'recording');
        $this->stepsAlreadyUsed($task, 40);
        $seen = new \ArrayObject();
        $adapter = new class($seen) implements ConversationChannelDelivery {
            public function __construct(private \ArrayObject $seen)
            {
            }

            public function channel(): string
            {
                return 'recording';
            }

            public function deliver(Conversation $conversation, string $text): DeliveryResult
            {
                $this->seen[] = Message::where('conversation_id', $conversation->id)->where('content', $text)->exists();

                return DeliveryResult::ok();
            }
        };
        $this->app->make(ConversationChannelRegistry::class)->register($adapter);

        $this->assertAllowed(app(TaskBudgetGate::class)->beforeStep($conversation, null));

        $this->assertSame([true], $seen->getArrayCopy(), 'one delivery, of a notice already stored in the conversation');
    }

    public static function failingAdapters(): array
    {
        return [
            'the channel reports a failure' => ['fail', 'chat not found'],
            'the channel throws' => ['throw', 'connection reset'],
        ];
    }

    #[Test]
    #[DataProvider('failingAdapters')]
    public function a_notice_that_cannot_be_delivered_never_holds_up_the_step_and_the_failure_is_recorded(string $how, string $reason): void
    {
        [$conversation, $task] = $this->withTask(steps: 50, channel: 'telegram');
        $this->stepsAlreadyUsed($task, 40);
        $how === 'fail' ? $this->telegram->failWith($reason) : $this->telegram->throwWith($reason);

        $decision = app(TaskBudgetGate::class)->beforeStep($conversation, null);

        $this->assertAllowed($decision, 'the step proceeds');
        $this->assertSame(41, (int) $this->row($task)->steps_used, 'the claim stands');
        $this->assertCount(1, $this->telegram->textsFor($conversation->id), 'one delivery attempt');

        $notices = $this->notices();
        $this->assertCount(1, $notices, 'the notice stays in the conversation\'s history, visible on the web');
        $this->assertSame($conversation->id, $notices[0]->conversation_id);

        $events = $this->approachEvents($task);
        $this->assertCount(1, $events);
        $this->assertSame(['steps'], $events[0]['ceilings']);
        $this->assertSame($notices[0]->id, $events[0]['message_id']);
        $this->assertFalse($events[0]['delivered'], 'the failure is recorded');
        $this->assertIsString($events[0]['delivery_failure_reason']);
        $this->assertStringContainsString($reason, $events[0]['delivery_failure_reason']);

        $logged = array_filter($this->logs, fn (MessageLogged $l) => in_array($l->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)
            && str_contains($l->message.' '.json_encode($l->context), $task->id));
        $this->assertNotEmpty($logged, 'the failure is logged, naming the task');
        $this->assertSame([], array_filter($this->logs, fn (MessageLogged $l) => str_contains($l->message, 'could not be checked')), 'the task budget itself was checked');
    }

    #[Test]
    public function a_task_nobody_can_be_told_about_keeps_its_notice_in_the_conversation_without_trying_to_deliver_it(): void
    {
        // An unattended task (a scheduled run, say): the conversation's
        // channel has an adapter, but nobody is there to read it. The
        // notice is part of the conversation's record, as the halt report
        // will be; no delivery is attempted, and that is no failure.
        $conversation = $this->conversation();
        $task = app(TaskBudgetService::class)->forNewRun($conversation, new StatedCeilings(maxSteps: 50, maxSpend: '1.00'), true, null);
        $this->stepsAlreadyUsed($task, 40);

        $this->assertAllowed(app(TaskBudgetGate::class)->beforeStep($conversation, null));

        $notices = $this->notices();
        $this->assertCount(1, $notices, 'written once, in the conversation');
        $this->assertSame($this->expectedText($task, 40, ['steps']), $notices[0]->content);
        $this->assertSame([], $this->web->deliveries, 'no delivery is attempted');
        $this->assertNotNull($this->row($task)->steps_approach_noticed_at, 'once per allowance, as for anyone');
        $this->assertSame([[
            'ceilings' => ['steps'],
            'message_id' => $notices[0]->id,
            'delivered' => false,
            'delivery_failure_reason' => 'This is an unattended run; no user is present to answer.',
        ]], $this->approachEvents($task));
        $this->assertSame([], array_values(array_filter($this->logs, fn (MessageLogged $l) => in_array($l->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true))), 'nothing failed');
    }

    #[Test]
    public function a_notice_that_cannot_even_be_written_never_stops_the_step(): void
    {
        [$conversation, $task] = $this->withTask(steps: 50);
        $this->stepsAlreadyUsed($task, 40);

        DB::statement("CREATE TRIGGER refuse_task_budget_notices BEFORE INSERT ON messages WHEN NEW.tool_data LIKE '%task_budget_notice%' BEGIN SELECT RAISE(ABORT, 'notices are refused'); END");
        try {
            $decision = app(TaskBudgetGate::class)->beforeStep($conversation, null);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS refuse_task_budget_notices');
        }

        $this->assertAllowed($decision, 'a notice failure never turns an allowed step into a stop');
        $this->assertSame(41, (int) $this->row($task)->steps_used, 'the claim stands');
        $this->assertCount(0, $this->notices());
        $this->assertSame([], $this->web->deliveries, 'nothing unstored is delivered');
        $this->assertSame('running', $this->row($task)->state);
    }

    #[Test]
    public function the_notice_never_enters_the_models_history(): void
    {
        [$conversation, $task] = $this->withTask(steps: 50);
        $this->message($conversation, 'user', 'Store notes for me.', now()->subMinute()->format('Y-m-d H:i:s'));
        $this->stepsAlreadyUsed($task, 40);

        $this->assertAllowed(app(TaskBudgetGate::class)->beforeStep($conversation, null));
        $notice = $this->notices()->first();
        $this->assertNotNull($notice, 'precondition: a notice was posted');

        $history = app(AgentLoopService::class)->buildHistoryMessages($conversation->fresh());

        $contents = array_column($history, 'content');
        $this->assertContains('Store notes for me.', $contents);
        $this->assertNotContains($notice->content, $contents, 'a system notice, never shown to the model');
    }

    #[Test]
    public function every_system_written_notice_kind_is_a_system_notice_kind(): void
    {
        $this->assertSame(
            [AgentQuestionService::KIND_NOTICE, AgentQuestionService::KIND_TASK_BUDGET_NOTICE, AgentQuestionService::KIND_TASK_BUDGET_REPORT],
            AgentQuestionService::SYSTEM_NOTICE_KINDS,
        );
    }

    public static function systemNotices(): array
    {
        return [
            'an approach notice' => [['kind' => 'task_budget_notice', 'task_budget_id' => 'b6f3a0de-1111-4f1f-9c2e-000000000001', 'notice' => 'approach']],
            'a task budget report' => [['kind' => 'task_budget_report', 'task_budget_id' => 'b6f3a0de-1111-4f1f-9c2e-000000000001', 'occasion' => 'halted']],
        ];
    }

    #[Test]
    #[DataProvider('systemNotices')]
    public function a_reply_typed_just_before_a_task_budget_notice_is_still_picked_up(array $toolData): void
    {
        $conversation = $this->conversation();
        $this->message($conversation, 'assistant', 'Storing the next note.', '2026-10-12 10:00:00');
        $this->message($conversation, 'user', 'Also tag them as drafts.', '2026-10-12 10:00:30');
        $this->message($conversation, 'assistant', "Heads up — this task's budget is 80% used …", '2026-10-12 10:00:31', $toolData);

        $loop = $this->mock(AgentLoopService::class);
        $loop->shouldReceive('start')->once()->withArgs(fn ($c) => $c->id === $conversation->id);

        $method = new \ReflectionMethod(AgentLoopStreamHandler::class, 'checkForUnprocessedMessages');
        $method->invoke(new AgentLoopStreamHandler(), $conversation);
    }

    #[Test]
    public function two_gates_in_different_scopes_crossing_together_post_one_notice(): void
    {
        [$conversation, $task] = $this->withTask(steps: 50);
        $this->stepsAlreadyUsed($task, 40);

        // Two workers: each has its own gate (and its own service graph),
        // and the second takes its whole step while the first is about to
        // take the steps latch — after it has seen the latch open.
        $first = $this->app->build(TaskBudgetGate::class);
        $second = $this->app->build(TaskBudgetGate::class);
        $secondConversation = Conversation::findOrFail($conversation->id);
        $interleaved = false;
        $secondDecision = null;
        DB::beforeExecuting(function (string $sql) use (&$interleaved, &$secondDecision, $second, $secondConversation) {
            if ($interleaved || preg_match('/^\s*update\s+"?task_budgets"?\s+set\s+.*approach_noticed_at/i', $sql) !== 1) {
                return;
            }
            $interleaved = true;
            $secondDecision = $second->beforeStep($secondConversation, null);
        });

        $firstDecision = $first->beforeStep($conversation, null);

        $this->assertTrue($interleaved, 'precondition: the first worker reached the latch write');
        $this->assertAllowed($firstDecision);
        $this->assertAllowed($secondDecision);
        $this->assertSame(42, (int) $this->row($task)->steps_used);
        $this->assertCount(1, $this->notices(), 'exactly one notice, from whichever worker took the latch');
        $this->assertCount(1, $this->web->textsFor($conversation->id));
        $this->assertCount(1, $this->approachEvents($task));
    }

    private function message(Conversation $conversation, string $role, string $content, string $at, ?array $toolData = null): void
    {
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
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
}
