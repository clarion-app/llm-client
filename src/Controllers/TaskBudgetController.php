<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\TaskBudgetEvent;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class TaskBudgetController extends Controller
{
    public function show(string $id)
    {
        $conversation = Conversation::where('id', $id)->where('user_id', Auth::id())->first();
        if ($conversation === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        $tasks = app(TaskBudgetService::class);
        $task = $tasks->currentFor($conversation);
        if ($task === null) {
            return response()->json(['task_budget' => null]);
        }

        return response()->json($tasks->standing($task)->toArray());
    }

    /**
     * The standing and event record of the latest task whose root or paused
     * run is $runId. An absent run and another user's run get the same 404
     * as the other run endpoints; a run with no task gets a null standing.
     */
    public function forRun(string $runId)
    {
        $runs = app(RunTraceQuery::class);
        if ($runs->findRun((string) Auth::id(), $runId) === null) {
            return response()->json(['error' => 'Run not found', 'code' => 'run_not_found'], 404);
        }
        $task = $runs->taskBudgetsForRuns([$runId])[$runId] ?? null;
        if ($task === null) {
            return response()->json(['standing' => null, 'events' => []]);
        }
        $events = TaskBudgetEvent::where('task_budget_id', $task->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (TaskBudgetEvent $event) => [
                'id' => $event->id,
                'kind' => $event->kind,
                'payload' => $event->payload,
                'actor_user_id' => $event->actor_user_id,
                // The stored value keeps its microseconds; the model's cast would not.
                'created_at' => Carbon::parse($event->getRawOriginal('created_at'), 'UTC')->toJSON(),
            ])
            ->all();

        return response()->json([
            'standing' => app(TaskBudgetService::class)->standing($task)->toArray(),
            'events' => $events,
        ]);
    }

    /**
     * Changes the ceilings of the conversation's running or paused task. A
     * paused task stays paused; the change governs its next step boundary.
     */
    public function update(Request $request, string $id)
    {
        $conversation = Conversation::where('id', $id)->where('user_id', Auth::id())->first();
        if ($conversation === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        $validated = $request->validate(self::rules(''));
        if (($validated['max_steps'] ?? null) === null && ($validated['max_spend'] ?? null) === null) {
            return response()->json(['message' => 'Give max_steps, max_spend or both.', 'errors' => ['max_steps' => ['Give max_steps, max_spend or both.']]], 422);
        }
        $tasks = app(TaskBudgetService::class);
        $task = $tasks->currentFor($conversation);
        $changed = ($task === null || $task->state->isTerminal()) ? null
            : $tasks->changeCeilings($task, new StatedCeilings($validated['max_steps'] ?? null, $validated['max_spend'] ?? null), (string) Auth::id());
        if ($changed === null) {
            return response()->json(['code' => 'no_active_task', 'message' => (new TaskBudgetText())->noTaskInProgress()], 409);
        }

        return response()->json($changed['standing']->toArray() + ['reduced' => $changed['reduced']]);
    }

    /**
     * The ceilings a request states: a body field wins key by key, a
     * "/budget" line fills only the keys the body leaves out. Null when
     * nothing is stated.
     */
    public static function statedFrom(?array $body, ?StatedCeilings $directive): ?StatedCeilings
    {
        $steps = $body['max_steps'] ?? $directive?->maxSteps;
        $stated = new StatedCeilings($steps === null ? null : (int) $steps, $body['max_spend'] ?? $directive?->maxSpend);

        return $stated->isEmpty() ? null : $stated;
    }

    /** Validation rules for stated ceilings, under $prefix ('' or 'task_budget.'). */
    public static function rules(string $prefix): array
    {
        return [
            $prefix.'max_steps' => ['sometimes', 'nullable', 'integer', 'min:1'],
            $prefix.'max_spend' => ['sometimes', 'nullable', 'regex:/^\d+(\.\d+)?$/', function ($attribute, $value, $fail) {
                if (!is_string($value) && !is_int($value) && !is_float($value) || preg_match('/^\d+(\.\d+)?$/', (string) $value) !== 1 || bccomp((string) $value, '0', 10) <= 0) {
                    $fail('The '.$attribute.' must be an amount greater than 0.');
                }
            }],
        ];
    }
}
