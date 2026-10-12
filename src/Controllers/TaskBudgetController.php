<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\TaskBudgetService;
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
}
