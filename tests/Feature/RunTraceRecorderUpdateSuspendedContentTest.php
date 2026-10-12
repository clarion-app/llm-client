<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\RunActionUpdated;
use ClarionApp\LlmClient\Services\ContentSanitizer;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * updateSuspendedActionContent() rewrites the content of an action that is
 * still waiting on a person — the `ask_user` action, once its question has
 * been delivered and how it was presented is known. It touches nothing
 * else, stores content exactly as the suspend path does, announces the
 * change like every other action write, and never throws.
 */
class RunTraceRecorderUpdateSuspendedContentTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.run_trace.enabled' => true]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function recorder(): RunTraceRecorder
    {
        return app(RunTraceRecorder::class);
    }

    private function action(): string
    {
        $run = $this->recorder()->openRun(RunKind::Interactive, (string) $this->user->id);
        $step = $this->recorder()->openStep($run);
        $action = $this->recorder()->openAction($step, ActionType::ToolInvocation, 'ask_user');
        $this->assertNotNull($action, 'precondition: the action was recorded');

        return $action;
    }

    private function suspendedAction(string $content = '{"resolution":"pending","presentation":{"mode":null}}'): string
    {
        $action = $this->action();
        $this->recorder()->closeAction($action, ActionOutcome::AwaitingConfirmation, content: $content);
        $this->assertSame('awaiting_confirmation', $this->outcome($action), 'precondition: the action waits on a person');

        return $action;
    }

    private function content(string $actionId): ?string
    {
        return DB::table('agent_run_actions')->where('id', $actionId)->value('content');
    }

    private function outcome(string $actionId): ?string
    {
        return DB::table('agent_run_actions')->where('id', $actionId)->value('outcome');
    }

    #[Test]
    public function the_content_of_a_waiting_action_is_replaced(): void
    {
        $action = $this->suspendedAction();
        $before = (array) DB::table('agent_run_actions')->where('id', $action)->first();

        $this->recorder()->updateSuspendedActionContent($action, '{"resolution":"pending","presentation":{"mode":"rich"}}');

        $after = (array) DB::table('agent_run_actions')->where('id', $action)->first();
        $this->assertSame('{"resolution":"pending","presentation":{"mode":"rich"}}', $after['content']);
        unset($before['content'], $after['content']);
        $this->assertSame($before, $after, 'nothing but the content changes; the action keeps waiting');
    }

    #[Test]
    public function an_action_that_is_not_waiting_is_left_alone(): void
    {
        $succeeded = $this->action();
        $this->recorder()->closeAction($succeeded, ActionOutcome::Success, content: 'final result');
        $failed = $this->action();
        $this->recorder()->closeAction($failed, ActionOutcome::Failure, failureReason: 'boom', content: 'failure detail');
        $running = $this->action();

        Event::fake([RunActionUpdated::class]);

        foreach ([$succeeded, $failed, $running] as $action) {
            $this->recorder()->updateSuspendedActionContent($action, 'rewritten');
        }

        $this->assertSame('final result', $this->content($succeeded));
        $this->assertSame('failure detail', $this->content($failed));
        $this->assertNull($this->content($running));
        Event::assertNotDispatched(RunActionUpdated::class);
    }

    #[Test]
    public function content_is_sanitized_and_capped_exactly_as_the_suspend_path_stores_it(): void
    {
        config(['llm-client.run_trace.action_content_cap_bytes' => 256]);
        app()->forgetInstance(ContentSanitizer::class);
        app()->forgetInstance(RunTraceRecorder::class);

        $content = '{"note":"Bearer abcdefghijklmnopqrstuvwxyz0123456789","padding":"'.str_repeat('x', 600).'"}';

        $viaSuspend = $this->suspendedAction($content);
        $updated = $this->suspendedAction();
        $this->recorder()->updateSuspendedActionContent($updated, $content);

        $stored = $this->content($updated);
        $this->assertSame($this->content($viaSuspend), $stored, 'stored exactly as the suspend path stores it');
        $this->assertSame(app(ContentSanitizer::class)->prepare($content), $stored);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz0123456789', $stored, 'secrets are redacted');
        $this->assertTrue(app(ContentSanitizer::class)->isTruncated($stored), 'oversized content is capped');
    }

    #[Test]
    public function the_change_is_announced_once(): void
    {
        $action = $this->suspendedAction();
        Event::fake([RunActionUpdated::class]);

        $this->recorder()->updateSuspendedActionContent($action, 'presented');

        Event::assertDispatchedTimes(RunActionUpdated::class, 1);
        Event::assertDispatched(RunActionUpdated::class, fn (RunActionUpdated $event) => $event->actionId === $action);
    }

    #[Test]
    public function a_failing_announcement_neither_throws_nor_undoes_the_write(): void
    {
        $action = $this->suspendedAction();
        Event::listen(RunActionUpdated::class, function (): void {
            throw new \RuntimeException('Pusher unreachable');
        });

        $this->recorder()->updateSuspendedActionContent($action, 'presented');

        $this->assertSame('presented', $this->content($action));
    }

    #[Test]
    public function a_missing_action_id_is_a_no_op(): void
    {
        Event::fake([RunActionUpdated::class]);

        $this->recorder()->updateSuspendedActionContent(null, 'anything');
        $this->recorder()->updateSuspendedActionContent((string) Str::uuid(), 'anything');

        Event::assertNotDispatched(RunActionUpdated::class);
        $this->assertSame(0, DB::table('agent_run_actions')->where('content', 'anything')->count());
    }

    #[Test]
    public function nothing_is_written_while_tracing_is_disabled(): void
    {
        $action = $this->suspendedAction('original');
        config(['llm-client.run_trace.enabled' => false]);
        Event::fake([RunActionUpdated::class]);

        $this->recorder()->updateSuspendedActionContent($action, 'rewritten');

        $this->assertSame('original', $this->content($action));
        Event::assertNotDispatched(RunActionUpdated::class);
    }

    #[Test]
    public function a_storage_failure_never_throws(): void
    {
        $action = $this->suspendedAction('original');
        DB::statement('ALTER TABLE agent_run_actions RENAME TO agent_run_actions_unavailable');

        try {
            $this->recorder()->updateSuspendedActionContent($action, 'rewritten');
            $this->assertTrue(true, 'no exception escaped');
        } finally {
            DB::statement('ALTER TABLE agent_run_actions_unavailable RENAME TO agent_run_actions');
        }

        $this->assertSame('original', $this->content($action));
    }
}
