<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalCaseVersion;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Models\EvalRunCase;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\ValueObjects\EvalCaseOutcome;
use ClarionApp\LlmClient\ValueObjects\EvalRunCaseStatus;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use ClarionApp\LlmClient\ValueObjects\ExpectationKind;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * The per-case unit of work (research.md D5/D6/D8): finds-or-creates the
 * case's dedicated system-owned Conversation, drives it through the real
 * AgentLoopService::run(), reads the transcript back (not run()'s own
 * return array — its content key is legitimately empty on the
 * all-successful-execute_operation completion path), judges it, and
 * writes the durable, once-written EvalCaseResult row. Performs the
 * "last job out" run-completion check (D5) after every case, success or
 * failure alike.
 */
class EvalCaseExecutor
{
    public function __construct(
        private readonly EvalCaseJudge $judge,
        private readonly AgentLoopService $agentLoop,
    ) {
    }

    public function execute(string $runId, string $evalRunCaseId): void
    {
        $evalRunCase = EvalRunCase::findOrFail($evalRunCaseId);

        // Idempotency guard first (research.md D8): a sibling attempt —
        // e.g. a queue redelivery — already wrote this case's result.
        if ($evalRunCase->status === EvalRunCaseStatus::Completed) {
            return;
        }

        $run = EvalRun::findOrFail($runId);

        try {
            $version = EvalCaseVersion::findOrFail($evalRunCase->eval_case_version_id);
            $conversation = $this->findOrCreateConversation($run, $evalRunCase);

            // The flag is set/cleared here even though nothing reads it
            // until US2 (Phase 4) wires the branches that check it.
            try {
                Context::add('eval_run_simulating_tools', true);
                $this->agentLoop->run($conversation, $version->given);
            } finally {
                Context::forget('eval_run_simulating_tools');
            }

            $messages = Message::where('conversation_id', $conversation->id)
                ->orderBy('created_at')
                ->get();

            $assistantMessages = $messages->where('role', 'assistant');

            $producedResponse = $assistantMessages->last()?->content;

            $attemptedActions = $assistantMessages
                ->flatMap(fn (Message $message) => collect($message->tool_data['tool_calls'] ?? [])
                    ->map(fn (array $toolCall) => $this->attemptedAction($toolCall)))
                ->filter()
                ->values()
                ->all();

            // Partition the version's expectations by kind, preserving each
            // entry's original array index as its expectation_index
            // (data-model.md §5) — a case may interleave rubric_judgment
            // expectations with any of the five checkable/human-review
            // kinds, and EvalCaseJudge has no arm for rubric_judgment at
            // all (it is judged here, not there).
            $rubricExpectations = [];
            $nonRubricExpectations = [];

            foreach ($version->expectations as $index => $expectation) {
                if (($expectation['kind'] ?? null) === ExpectationKind::RubricJudgment->value) {
                    $rubricExpectations[$index] = $expectation;
                } else {
                    $nonRubricExpectations[$index] = $expectation;
                }
            }

            $judged = $this->judge->judge(array_values($nonRubricExpectations), $producedResponse, $attemptedActions);

            // EvalCaseJudge::judge() returns its results in the same order
            // it was given $nonRubricExpectations, which array_values()
            // preserved from $nonRubricExpectations's own (ascending,
            // original) key order — so re-combining with those same keys
            // is safe.
            $resultsByIndex = array_combine(array_keys($nonRubricExpectations), $judged['expectation_results']);

            // A case with zero rubric_judgment expectations never reaches
            // any of the code below this guard — no dedicated judge
            // Conversation is resolved, no RubricJudge call is made, no
            // eval_judgments row is written. Byte-identical to the
            // pre-rubric-judging path (data-model.md §5).
            $pendingJudgments = [];

            if ($rubricExpectations !== []) {
                // One dedicated, system-owned judge Conversation per case
                // (research.md D3), reused across however many
                // rubric_judgment expectations this case has — distinct
                // from the case's own agent Conversation resolved above.
                $judgeConversation = Conversation::firstOrCreate(
                    ['title' => 'eval-judgment:'.$evalRunCase->id],
                    ['user_id' => null, 'character' => 'eval-judge'],
                );

                foreach ($rubricExpectations as $index => $expectation) {
                    $criteria = (string) ($expectation['criteria'] ?? '');
                    $judgmentId = (string) Str::uuid();

                    $result = app(RubricJudge::class)->judge(
                        $criteria,
                        $version->given,
                        $producedResponse,
                        $attemptedActions,
                        $judgeConversation,
                        'eval_rubric_judgment',
                    );

                    $resultsByIndex[$index] = [
                        'kind' => ExpectationKind::RubricJudgment->value,
                        'criteria' => $criteria,
                        'met' => $result->status === 'judged'
                            ? $result->score >= (int) config('llm-client.eval_judging.passing_score', 7)
                            : null,
                        'score' => $result->score,
                        'status' => $result->status,
                        'judgment_id' => $judgmentId,
                    ];

                    $pendingJudgments[] = [
                        'judgment_id' => $judgmentId,
                        'expectation_index' => $index,
                        'criteria' => $criteria,
                        'result' => $result,
                    ];
                }
            }

            // Merge rubric and non-rubric expectation_results back into
            // the version's original expectations[] index order.
            ksort($resultsByIndex);
            $expectationResults = array_values($resultsByIndex);

            $outcome = EvalCaseOutcome::aggregate($expectationResults);

            $caseResult = $this->recordResult(
                $run,
                $evalRunCase,
                $conversation->id,
                $outcome,
                $producedResponse,
                $attemptedActions,
                $expectationResults,
                null,
            );

            // Written only after the EvalCaseResult row exists, so each
            // judgment's eval_case_result_id can reference it in the same
            // logical operation (data-model.md §1/§5's write-ordering
            // note).
            foreach ($pendingJudgments as $pending) {
                app(EvalJudgmentService::class)->record(
                    $pending['judgment_id'],
                    $caseResult->id,
                    $version->id,
                    $pending['expectation_index'],
                    $pending['criteria'],
                    $producedResponse,
                    $pending['result'],
                );
            }
        } catch (\Throwable $e) {
            $this->recordTimeoutOrFailure($runId, $evalRunCaseId, $e);
        }
    }

    /**
     * The RunEvalCaseJob::failed() hook lands here — a queue-worker-level
     * timeout kill, or (via execute()'s own catch) an outright exception
     * thrown inside AgentLoopService::run(), including a
     * BudgetExceededException (research.md D10 — no exemption, contained
     * per-case like any other failure, never aborting the run).
     */
    public function recordTimeoutOrFailure(string $runId, string $evalRunCaseId, \Throwable $e): void
    {
        $evalRunCase = EvalRunCase::find($evalRunCaseId);

        if ($evalRunCase === null || $evalRunCase->status === EvalRunCaseStatus::Completed) {
            return;
        }

        $run = EvalRun::findOrFail($runId);
        $conversation = $this->findOrCreateConversation($run, $evalRunCase);

        $this->recordResult(
            $run,
            $evalRunCase,
            $conversation->id,
            EvalCaseOutcome::Errored,
            null,
            [],
            [],
            $e->getMessage(),
        );
    }

    /**
     * One dedicated Conversation per case (research.md D6), findable by a
     * deterministic title so a redelivery or an out-of-band failure
     * recording never has to invent a second conversation for the same
     * case.
     */
    private function findOrCreateConversation(EvalRun $run, EvalRunCase $evalRunCase): Conversation
    {
        return Conversation::firstOrCreate(
            ['title' => $this->conversationTitle($evalRunCase)],
            [
                'user_id' => null,
                'character' => $run->agent_label,
                'server_id' => $run->server_id,
                'model' => $run->model,
            ],
        );
    }

    private function conversationTitle(EvalRunCase $evalRunCase): string
    {
        return 'eval-run-case:'.$evalRunCase->id;
    }

    private function recordResult(
        EvalRun $run,
        EvalRunCase $evalRunCase,
        ?string $conversationId,
        EvalCaseOutcome $outcome,
        ?string $producedResponse,
        array $attemptedActions,
        array $expectationResults,
        ?string $errorMessage,
    ): EvalCaseResult {
        $caseResult = EvalCaseResult::create([
            'run_id' => $run->id,
            'eval_run_case_id' => $evalRunCase->id,
            'eval_case_id' => $evalRunCase->eval_case_id,
            'eval_case_version_id' => $evalRunCase->eval_case_version_id,
            'conversation_id' => $conversationId,
            'outcome' => $outcome,
            'produced_response' => $producedResponse,
            'attempted_actions' => $attemptedActions,
            'expectation_results' => $expectationResults,
            'error_message' => $errorMessage,
        ]);

        $evalRunCase->update(['status' => EvalRunCaseStatus::Completed]);

        $this->maybeCompleteRun($run);

        return $caseResult;
    }

    /**
     * "Last job out closes the door" (research.md D5): after every case
     * writes its result, re-count eval_run_cases rows for this run still
     * lacking a matching eval_case_results row. If none remain, the run
     * is complete.
     */
    private function maybeCompleteRun(EvalRun $run): void
    {
        $stillIncomplete = EvalRunCase::where('run_id', $run->id)
            ->whereNotIn(
                'id',
                EvalCaseResult::where('run_id', $run->id)->pluck('eval_run_case_id'),
            )
            ->count();

        if ($stillIncomplete > 0) {
            // data-model.md §1: eval_runs.updated_at is load-bearing for
            // ResolveStalledEvalRunsCommand's staleness detection — "every
            // case-completion write that touches this row must bump it,"
            // not only the run's final transition to `completed`. Without
            // this, a run that is still actively, successfully making
            // progress past `stale_after_minutes` (a large, legitimately
            // long-running suite — exactly the shape this feature targets)
            // would be misdiagnosed as stalled by the next sweep tick, which
            // resets every still-`dispatched` case (including ones a live
            // worker is processing *right now*) back to `pending` and
            // redispatches them — racing a real, in-flight case execution
            // for no reason.
            $run->touch();

            return;
        }

        $run->update([
            'status' => EvalRunStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    /**
     * Only an execute_operation call is a judgeable "action" in the
     * action_taken/action_not_taken sense — the other meta-tools
     * (search_operations, list_applications, memory_create, ...) are
     * agent orchestration mechanics, not the named actions a case's
     * expectations refer to.
     *
     * @param  array<string, mixed>  $toolCall  an OpenAI-shaped tool_calls[]
     *   entry from a Message's tool_data.
     * @return array{tool: ?string, arguments: array}|null
     */
    private function attemptedAction(array $toolCall): ?array
    {
        $functionName = $toolCall['function']['name'] ?? null;

        if ($functionName !== 'execute_operation') {
            return null;
        }

        $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];

        return [
            'tool' => $arguments['operationId'] ?? null,
            'arguments' => $arguments['parameters'] ?? [],
        ];
    }
}
