<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\RunCompletionHooks;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A completion hook runs once for a run that has ended, however many times
 * the run is passed to it, and never for a run that is still going.
 */
class RunCompletionHooksTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('agent_runs')->delete();
        parent::tearDown();
    }

    private function makeRun(string $endState): string
    {
        $id = (string) Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $id,
            'kind' => 'interactive',
            'user_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'end_state' => $endState,
            'started_at' => now(),
            'resume_options' => json_encode(['on_complete' => ['hook' => 'no_such_hook']]),
        ]);

        return $id;
    }

    /** @return list<string> */
    private function hookLogsDuring(callable $work): array
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged) {
            if (str_contains($event->message, 'no_such_hook')) {
                $logged[] = $event->message;
            }
        });
        $work();

        return $logged;
    }

    #[Test]
    public function an_ended_run_runs_its_hook_once_however_often_it_is_passed_in(): void
    {
        $id = $this->makeRun('completed');

        $logged = $this->hookLogsDuring(function () use ($id) {
            $hooks = app(RunCompletionHooks::class);
            $hooks->run($id);
            $hooks->run($id);
            $hooks->run($id);
        });

        $this->assertCount(1, $logged);
    }

    /**
     * Two processes end the same run at once (a resumed run's end and a
     * stop with a report): both read the hook as not yet run. Only the one
     * whose compare-and-set lands runs it.
     */
    #[Test]
    public function a_hook_another_process_claimed_after_this_one_read_it_is_not_run_again(): void
    {
        $id = $this->makeRun('completed');

        // The other process claims the hook right after this one has read
        // the run row (between the read and this process's own claim).
        $claimedByOther = false;
        DB::listen(function ($query) use ($id, &$claimedByOther) {
            if ($claimedByOther || !str_starts_with(strtolower($query->sql), 'select') || !str_contains($query->sql, 'resume_options')) {
                return;
            }
            $claimedByOther = true;
            DB::table('agent_runs')->where('id', $id)->update([
                'resume_options' => json_encode(['on_complete' => ['hook' => 'no_such_hook', 'ran_at' => now()->toIso8601String()]]),
            ]);
        });

        $logged = $this->hookLogsDuring(fn () => app(RunCompletionHooks::class)->run($id));

        $this->assertTrue($claimedByOther, 'precondition: the other process claimed between the read and the claim');
        $this->assertSame([], $logged, 'the hook the other process claimed is not run a second time');
    }

    #[Test]
    public function a_run_that_is_still_going_runs_no_hook(): void
    {
        $id = $this->makeRun('in_progress');

        $logged = $this->hookLogsDuring(fn () => app(RunCompletionHooks::class)->run($id));

        $this->assertSame([], $logged);
    }
}
