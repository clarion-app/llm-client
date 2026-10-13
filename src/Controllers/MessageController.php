<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ServerGroup;
use Illuminate\Http\Request;
use Auth;
use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\HttpQueue\SendHttpRequest;
use Illuminate\Support\Facades\Log;
use ClarionApp\LlmClient\OpenAIConversationRequest;
use ClarionApp\LlmClient\OpenAIConversationStreamRequest;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\RateLimitGate;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Services\QuestionAnswerIntake;
use ClarionApp\LlmClient\Services\LateReplyNotifier;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\QuestionViewBuilder;
use ClarionApp\LlmClient\ValueObjects\IntakeResult;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\Services\TaskBudgetDirectiveParser;
use ClarionApp\LlmClient\Services\TaskBudgetService;
use ClarionApp\LlmClient\Services\TaskBudgetText;

class MessageController extends Controller
{
    public function index($conversation_id)
    {
        //TODO: Implement Spatie permissions
        $conversation = Conversation::ownedByRealUser()->find($conversation_id);
        if(!$conversation) return response()->json([], 404);
        
        $isOwner = $conversation->user_id == Auth::id();
        if(!$isOwner)
        {
            if(!Auth::user()->can('list users')) return response()->json([], 403);
        }

        $messages = Message::where('conversation_id', $conversation_id)->orderBy('created_at')->get();
        foreach($messages as &$message) $message->streaming = false;
        unset($message);

        // The owner's screen renders each question from its current stored
        // state; someone else reading the conversation sees only the text.
        if ($isOwner) {
            $views = app(QuestionViewBuilder::class)->forMessages($messages);
            foreach ($messages as $message) {
                if (isset($views[$message->id])) {
                    $message->question = $views[$message->id];
                }
            }
        }

        return response()->json($messages, 200);
    }

    public function store(Request $request)
    {
        // `content` may be blank only so a blank reply to a pending question
        // can be refused with answer_required; without a pending question a
        // blank message is the same validation error it always was.
        $validatedData = $request->validate([
            'content' => 'present|nullable|string',
            'conversation_id' => 'required|exists:conversations,id',
            'task_budget' => ['sometimes', 'nullable', 'array'],
        ] + TaskBudgetController::rules('task_budget.'));
        $content = (string) ($validatedData['content'] ?? '');
        $bodyBudget = $validatedData['task_budget'] ?? null;
        unset($validatedData['task_budget']);

        // Only the requester's own pending question lets a blank message
        // through, so a blank message can never reveal whether someone
        // else's conversation is waiting on its user.
        if (trim($content) === ''
            && !AgentQuestion::where('pending_conversation_id', $validatedData['conversation_id'])
                ->where('user_id', Auth::id())
                ->exists()
        ) {
            $request->validate(['content' => 'required|string']);
        }

        $conversation = Conversation::ownedByRealUser()->find($validatedData['conversation_id']);
        if(!$conversation || $conversation->user_id != Auth::id())
        {
            return response()->json([], 403);
        }

        // A "/budget" line is read before the request is admitted or taken as
        // an answer: changing or viewing the ceilings is not a request, and
        // a ceiling changed while a ceiling prompt waits leaves it waiting.
        $directive = (new TaskBudgetDirectiveParser())->parse($content);
        $text = new TaskBudgetText();
        if ($directive['malformed']) {
            return response()->json(['code' => 'directive_malformed', 'message' => $text->directiveMalformed()], 422);
        }
        if ($directive['viewOnly'] || $directive['directiveOnly']) {
            $tasks = app(TaskBudgetService::class);
            $task = $tasks->currentFor($conversation);
            if ($task === null || $task->state->isTerminal()) {
                return response()->json(['code' => 'no_active_task', 'message' => $text->noTaskInProgress()], 409);
            }
            if ($directive['viewOnly']) {
                return response()->json($tasks->standing($task)->toArray());
            }
            $changed = $tasks->changeFromConversation($conversation, $task, $directive['stated'], (string) Auth::id());
            if ($changed === null) {
                return response()->json(['code' => 'no_active_task', 'message' => $text->noTaskInProgress()], 409);
            }

            return response()->json($changed['standing']->toArray() + ['reduced' => $changed['reduced']]);
        }
        // A "/budget" line ahead of a request states the ceilings for it and
        // is not part of the request; a body field wins key by key.
        if ($directive['stated'] !== null) {
            $content = $directive['remaining'];
        }
        $stated = TaskBudgetController::statedFrom($bodyBudget, $directive['stated']);

        // Before Message::create(), not after. A refused request must leave the
        // stored history byte-identical, and the user's own turn is the half
        // that is easy to miss: nothing downstream can tell a stored message
        // for work that never happened apart from one that was answered —
        // condensation will summarise it, memory capture will remember it, and
        // the next turn's context will include it.
        //
        // This is an ordering requirement layered on the funnel, not a second
        // decision point: it calls the same gates, and the checks inside
        // start() below remain as defence in depth, free because each gate
        // admits a scope once per request.
        //
        // Rate limit first — cheaper on the common case (zero database
        // queries when nothing is configured for the user) and orthogonal to
        // the spending ceiling, so there is no ordering between the two to
        // get backwards.
        app(RateLimitGate::class)->admit(
            (string) Auth::id(),
            BudgetWorkKind::Interactive,
            $conversation->id,
        );

        app(BudgetGate::class)->admit(
            (string) Auth::id(),
            BudgetWorkKind::Interactive,
            $conversation->id,
        );

        // A reply to the conversation's pending question is recorded as its
        // answer (intake stores the message itself) and resumes the paused
        // run; the continuation arrives over the conversation's broadcasts.
        $intake = app(QuestionAnswerIntake::class)->accept($conversation, (string) Auth::id(), $content);

        if ($intake->kind === IntakeResult::ANSWER_REQUIRED) {
            return response()->json([
                'code' => 'answer_required',
                'message' => 'An answer is required to reply to the pending question.',
            ], 422);
        }

        if ($intake->kind === IntakeResult::DECISION_REQUIRED) {
            return response()->json(['code' => 'decision_required', 'message' => $intake->guidance], 422);
        }

        if ($intake->kind === IntakeResult::DECISION_RECORDED) {
            return response()->json($intake->message, 201);
        }

        if ($intake->kind === IntakeResult::ANSWERED) {
            // This response carries only the answer message. A question
            // paused on the streaming path continues over the conversation's
            // broadcasts; one paused on the synchronous path (a Telegram
            // conversation answered from this screen) returns its reply
            // here, so the resumer pushes it to the conversation's own
            // channel, as it does when no request is waiting at all.
            app(QuestionResumer::class)->resumeAfterAnswer($intake->question);

            return response()->json($intake->message, 201);
        }

        $validatedData['content'] = $content;
        $validatedData['role'] = "user";
        $validatedData['user'] = Auth::user()->name;

        $message = Message::create($validatedData);

        // A reply that came after the conversation's latest question had
        // already expired on its assumption is told so, once, right after
        // the reply in the history. It is still handled as a new message.
        app(LateReplyNotifier::class)->noticeFor($conversation, (string) Auth::id());

        // Read fresh: a question resolved a moment ago (e.g. by expiry) has
        // already marked the conversation processing for its resumed run, and
        // this message must not start a second, concurrent one.
        if (!Conversation::whereKey($conversation->id)->value('is_processing')) {
            $agentLoopService = app(AgentLoopService::class);
            $agentLoopService->start($conversation, 1, null, $message->id, $stated);
        }

        $line = app(TaskBudgetService::class)->reducedLine($stated);
        if ($line !== null) {
            return response()->json($message->toArray() + ['task_budget_notice' => $line], 201);
        }

        return response()->json($message, 201);
    }

    public function show(Message $message)
    {
        $conversation = Conversation::ownedByRealUser()->find($message->conversation_id);
        if (!$conversation || $conversation->user_id != Auth::id()) {
            return response()->json([], 403);
        }

        return response()->json($message, 200);
    }

    public function update(Request $request, Message $message)
    {
        $validatedData = $request->validate([
            'content' => 'required|string',
            'role' => 'required|in:assistant,user,system',
            'user' => 'required|string',
            'responseTime' => 'nullable|integer',
            'conversation_id' => 'required|exists:conversations,id'
        ]);

        $conversationId = $validatedData['conversation_id'];
        $conversation = Conversation::find($conversationId);
        if (!$conversation || $conversation->user_id != Auth::id())
        {
            return response()->json([], 403);
        }

        $message->update($validatedData);
        return response()->json($message, 200);
    }

    public function destroy($id)
    {
        $message = Message::with('conversation')->find($id);

        if($message->conversation->user_id != Auth::id())
        {
            return response()->json([], 403);
        }

        $message->delete();
        return response()->json([], 204);
    }
}
