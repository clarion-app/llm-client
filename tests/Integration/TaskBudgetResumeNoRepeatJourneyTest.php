<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\QuestionAnswerIntake;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\Services\TaskBudgetPauser;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * Stopping at the ceiling and continuing later repeats nothing.
 *
 * Every step stores a note through the host application's API — a change in
 * the outside world, counted. The task stops at its two-step ceiling after
 * two notes, the user chooses stop, and the application restarts: nothing
 * of the paused task is held in memory anywhere. When the user's continue
 * arrives later, the task resumes once from where it paused: the counter
 * shows the two notes from before plus only what the new step stored, and
 * the model's first request after the pause sees every result stored
 * before it.
 */
class TaskBudgetResumeNoRepeatJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();
        config(['llm-client.agent_loop.max_iterations' => 2]);
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    /** As after a process restart: no resolved service survives, and nothing is read from memory. */
    private function restart(): void
    {
        foreach ([
            AgentLoopService::class,
            QuestionResumer::class,
            QuestionAnswerIntake::class,
            AgentQuestionService::class,
            TaskBudgetService::class,
            TaskBudgetGate::class,
            TaskBudgetPauser::class,
            ConversationChannelRegistry::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }
        $this->app->forgetScopedInstances();
    }

    /** @return list<string> */
    private function answeredCallIds(CapturedPayload $payload): array
    {
        $messages = json_decode(json_encode($payload->messages), true);

        return array_values(array_filter(array_map(
            fn (array $m) => ($m['role'] ?? null) === 'tool' ? ($m['tool_call_id'] ?? null) : null,
            $messages,
        )));
    }

    #[Test]
    #[DataProvider('paths')]
    public function a_late_continue_after_a_stop_and_a_restart_repeats_no_completed_action(string $path): void
    {
        $this->scenario = 'task_budget_resume_no_repeat_'.$path;
        $this->entryPath = $path;
        $conversation = $this->conversationFor($path);

        // Two steps, two notes stored, then the ceiling.
        if ($path === 'sync') {
            $this->scriptSteps(1, 2);
            $this->send('sync', $conversation, 'Store notes.')->assertStatus(202);
        } else {
            $this->send('stream', $conversation, 'Store notes.')->assertStatus(201);
            $this->playSteps(1, 2);
        }
        $this->assertCount(2, $this->noteWrites);
        $prompt = $this->promptOf($conversation);
        $runId = $this->latestRunIdOf($conversation);

        // Stop, then the application restarts.
        $this->send($path, $conversation, 'stop')->assertSuccessful();
        $this->assertSame(QuestionState::Stopped, $prompt->fresh()->state);
        $this->restart();
        $this->travel(6)->hours();

        // The late continue.
        if ($path === 'sync') {
            $this->scriptSteps(3, 3);
            $this->script()->finalAnswer('All notes stored.');
        }
        $response = $this->send($path, Conversation::findOrFail($conversation->id), 'continue')->assertSuccessful();
        if ($path === 'stream') {
            $this->playSteps(3, 3);
            $this->playTurn(3, 'All notes stored.');
        } else {
            $response->assertJson(['status' => 'completed', 'content' => 'All notes stored.']);
        }

        $this->assertCount(3, $this->noteWrites, 'the two notes from before the pause, and only the new step\'s one');
        $this->assertSame(['note 1', 'note 2', 'note 3'], array_map(fn (array $write) => $write['text'] ?? null, $this->noteWrites));
        $this->assertSame(4, $this->modelRequests($path));
        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), 'the paused run resumed');
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $runId)->value('end_state'));

        // The first request after the pause saw every result stored before it.
        $payloads = $path === 'sync' ? $this->transport()->capturedChatPayloads() : $this->stream()->extractDispatchedJobs()->capturedPayloads();
        $resumed = array_values($payloads)[2];
        foreach (['call_note_1', 'call_apps_1', 'call_note_2', 'call_apps_2'] as $id) {
            $this->assertContains($id, $this->answeredCallIds($resumed));
        }
        $this->assertSame(array_unique($this->answeredCallIds($resumed)), $this->answeredCallIds($resumed), 'each stored result appears once');
    }
}
