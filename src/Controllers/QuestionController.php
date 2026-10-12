<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\InteractiveAnswerService;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\QuestionViewBuilder;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\Services\RateLimitGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Answers a pending question from the conversation screen's form.
 *
 * The body names a selection of choice numbers or free text, never both.
 * The answer is applied through the same path as every interactive answer,
 * so it means exactly what the equivalent typed reply would, and the
 * paused run continues as it does after a typed reply from the screen.
 */
class QuestionController extends Controller
{
    public const ANSWER_REQUIRED_MESSAGE = 'Choose an option or type an answer.';

    /**
     * The longest free-text answer, in bytes: the answer is stored verbatim
     * in a TEXT column, which holds 65,535 bytes on MySQL.
     */
    public const MAX_TEXT_BYTES = 65535;

    public function answer(Request $request, string $id): JsonResponse
    {
        $answer = $this->answerFrom($request);
        if (is_string($answer)) {
            return response()->json(['code' => 'invalid_answer', 'message' => $answer], 422);
        }

        $userId = (string) Auth::id();

        // A question that does not exist and one that belongs to someone
        // else get the same bare response, so neither can be told apart.
        $row = DB::table('agent_questions')->where('id', $id)->first(['user_id', 'conversation_id']);
        if ($row === null || (string) $row->user_id !== $userId) {
            return response()->json(new \stdClass(), 404);
        }

        // Admitted before anything is recorded: a refused request leaves the
        // question waiting, exactly as it was.
        app(RateLimitGate::class)->admit($userId, BudgetWorkKind::Interactive, (string) $row->conversation_id);
        app(BudgetGate::class)->admit($userId, BudgetWorkKind::Interactive, (string) $row->conversation_id);

        $outcome = app(InteractiveAnswerService::class)->record($id, $userId, $answer, AnswerMethod::WebForm);
        $views = app(QuestionViewBuilder::class);

        switch ($outcome->status) {
            case InteractiveAnswerStatus::Answered:
                app(QuestionResumer::class)->resumeAfterAnswer($outcome->question);
                $question = AgentQuestion::find($id) ?? $outcome->question;

                return response()->json([
                    'status' => 'answered',
                    'question' => $views->forQuestion($question),
                    'message' => $outcome->message,
                ], 201);

            case InteractiveAnswerStatus::AnswerRequired:
                return response()->json(['code' => 'answer_required', 'message' => self::ANSWER_REQUIRED_MESSAGE], 422);

            case InteractiveAnswerStatus::InvalidAnswer:
                return response()->json([
                    'code' => 'invalid_answer',
                    'message' => 'That answer does not fit this question\'s choices.',
                ], 422);

            case InteractiveAnswerStatus::NotPending:
                $question = $outcome->question ?? AgentQuestion::find($id);

                return response()->json([
                    'code' => 'question_not_pending',
                    'state' => $outcome->state?->value,
                    'question' => $question !== null ? $views->forQuestion($question) : null,
                ], 409);

            case InteractiveAnswerStatus::NotFound:
            default:
                return response()->json(new \stdClass(), 404);
        }
    }

    /**
     * The submitted answer, or the reason the body is not one. Read from
     * the raw JSON body so free text reaches the question exactly as typed,
     * untouched by any input trimming.
     */
    private function answerFrom(Request $request): InteractiveAnswer|string
    {
        $body = $request->isJson() ? json_decode((string) $request->getContent(), true) : $request->all();
        if (!is_array($body)) {
            $body = [];
        }

        $hasSelected = array_key_exists('selected', $body);
        $hasText = array_key_exists('text', $body);

        if ($hasSelected === $hasText) {
            return 'Send either the selected choices or your own words, not both.';
        }

        if ($hasText) {
            if (!is_string($body['text'])) {
                return 'Your own words must be text.';
            }

            return strlen($body['text']) > self::MAX_TEXT_BYTES
                ? 'Your answer is too long.'
                : InteractiveAnswer::freeText($body['text']);
        }

        $selected = $body['selected'];
        if (!is_array($selected) || !array_is_list($selected)) {
            return 'The selected choices must be a list of choice numbers.';
        }
        foreach ($selected as $number) {
            if (!is_int($number)) {
                return 'The selected choices must be a list of choice numbers.';
            }
        }

        return InteractiveAnswer::selection($selected);
    }
}
