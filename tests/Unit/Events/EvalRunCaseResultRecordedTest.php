<?php

namespace ClarionApp\LlmClient\Tests\Unit\Events;

use ClarionApp\LlmClient\Events\EvalRunCaseResultRecorded;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EvalRunCaseResultRecorded broadcasts one case's small completion tick to
 * every configured operator -- never to any notion of "who owns this
 * case," since an eval run (and the case results it produces) has no
 * owner. The payload deliberately excludes the case's full content
 * (produced_response/expectation_results/attempted_actions); a live
 * viewer refetches the full case detail on demand instead.
 *
 * Written before the ClarionApp\LlmClient\Events\EvalRunCaseResultRecorded
 * class exists -- every test below is expected to fail with a
 * class-not-found error until that class is created. That failure is the
 * correct, expected state right now.
 */
class EvalRunCaseResultRecordedTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('eval_case_results')->delete();
        DB::table('eval_runs')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function createRun(array $overrides = []): EvalRun
    {
        return EvalRun::create(array_merge([
            'suite_id' => (string) Str::uuid(),
            'agent_label' => 'watch-live-agent',
            'status' => EvalRunStatus::InProgress,
            'case_count' => 1,
            'started_at' => now(),
        ], $overrides));
    }

    private function createCaseResult(EvalRun $run, array $overrides = []): EvalCaseResult
    {
        return EvalCaseResult::create(array_merge([
            'run_id' => $run->id,
            'eval_run_case_id' => (string) Str::uuid(),
            'eval_case_id' => (string) Str::uuid(),
            'eval_case_version_id' => (string) Str::uuid(),
            // Deliberately not a real Conversation row -- broadcastOn()
            // must never resolve a channel through this id. There is no
            // run owner and no case owner, only configured operators.
            'conversation_id' => (string) Str::uuid(),
            'outcome' => 'pass',
            'produced_response' => 'the produced response text',
            'attempted_actions' => [['tool' => 'some_tool', 'arguments' => []]],
            'expectation_results' => [['kind' => 'text_match', 'criteria' => 'x', 'met' => true]],
        ], $overrides));
    }

    #[Test]
    public function broadcast_on_resolves_to_one_private_channel_per_configured_operator(): void
    {
        $operatorA = (string) Str::uuid();
        $operatorB = (string) Str::uuid();
        config(['llm-client.cost.operator_user_ids' => [$operatorA, $operatorB]]);

        $run = $this->createRun();
        $caseResult = $this->createCaseResult($run);

        $channels = (new EvalRunCaseResultRecorded($caseResult->id))->broadcastOn();

        $this->assertCount(2, $channels);
        $this->assertSame(
            ['private-User.'.$operatorA, 'private-User.'.$operatorB],
            array_map(fn (PrivateChannel $c) => (string) $c, $channels),
        );
    }

    #[Test]
    public function broadcast_on_targets_every_configured_operator_never_the_cases_own_conversation(): void
    {
        // An implementation that resolved the case's own (system-owned,
        // ownerless) conversation and broadcast to that instead of the
        // configured operator list would still pass a looser "gets at
        // least one channel" check -- assert the exact channel set to
        // catch that.
        $operator = (string) Str::uuid();
        config(['llm-client.cost.operator_user_ids' => [$operator]]);

        $run = $this->createRun();
        $caseResult = $this->createCaseResult($run);

        $channels = (new EvalRunCaseResultRecorded($caseResult->id))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('private-User.'.$operator, (string) $channels[0]);
    }

    #[Test]
    public function broadcast_on_resolves_to_empty_array_when_the_case_result_has_since_been_purged(): void
    {
        config(['llm-client.cost.operator_user_ids' => [(string) Str::uuid()]]);

        $event = new EvalRunCaseResultRecorded((string) Str::uuid());

        $this->assertSame([], $event->broadcastOn());
    }

    #[Test]
    public function broadcast_on_resolves_to_empty_array_when_the_owning_run_has_since_been_purged(): void
    {
        config(['llm-client.cost.operator_user_ids' => [(string) Str::uuid()]]);

        $run = $this->createRun();
        $caseResult = $this->createCaseResult($run);

        DB::table('eval_runs')->where('id', $run->id)->delete();

        $this->assertSame([], (new EvalRunCaseResultRecorded($caseResult->id))->broadcastOn());
    }

    #[Test]
    public function broadcast_with_payload_is_exactly_the_six_small_fields(): void
    {
        config(['llm-client.cost.operator_user_ids' => [(string) Str::uuid()]]);

        $run = $this->createRun();
        $caseResult = $this->createCaseResult($run, ['outcome' => 'fail']);

        $payload = (new EvalRunCaseResultRecorded($caseResult->id))->broadcastWith();

        $this->assertSame(
            ['id', 'run_id', 'eval_case_id', 'outcome', 'outcome_override', 'created_at'],
            array_keys($payload),
        );
        $this->assertSame($caseResult->id, $payload['id']);
        $this->assertSame($run->id, $payload['run_id']);
        $this->assertSame($caseResult->eval_case_id, $payload['eval_case_id']);
        $this->assertSame('fail', $payload['outcome']);
        $this->assertNull($payload['outcome_override']);
    }

    #[Test]
    public function broadcast_with_never_includes_the_cases_full_content(): void
    {
        config(['llm-client.cost.operator_user_ids' => [(string) Str::uuid()]]);

        $run = $this->createRun();
        $caseResult = $this->createCaseResult($run, [
            'produced_response' => 'sensitive full response text that must never leak onto a small live tick',
        ]);

        $payload = (new EvalRunCaseResultRecorded($caseResult->id))->broadcastWith();

        $this->assertArrayNotHasKey('produced_response', $payload);
        $this->assertArrayNotHasKey('expectation_results', $payload);
        $this->assertArrayNotHasKey('attempted_actions', $payload);
    }

    #[Test]
    public function broadcast_with_reflects_an_overridden_outcome_at_broadcast_time(): void
    {
        config(['llm-client.cost.operator_user_ids' => [(string) Str::uuid()]]);

        $run = $this->createRun();
        $caseResult = $this->createCaseResult($run, ['outcome' => 'fail']);
        $event = new EvalRunCaseResultRecorded($caseResult->id);

        $caseResult->update(['outcome_override' => 'pass']);

        $payload = $event->broadcastWith();

        $this->assertSame('fail', $payload['outcome'], 'the original, once-written outcome column is never touched');
        $this->assertSame('pass', $payload['outcome_override']);
    }
}
