<?php

namespace ClarionApp\LlmClient\ValueObjects;

/** What happened to an interactive answer. */
enum InteractiveAnswerStatus: string
{
    /** Recorded as the question's answer. */
    case Answered = 'answered';

    /** Nothing was selected or typed; the question keeps waiting. */
    case AnswerRequired = 'answer_required';

    /** The selection does not fit the question; the question keeps waiting. */
    case InvalidAnswer = 'invalid_answer';

    /** The question was already resolved; nothing was written. */
    case NotPending = 'not_pending';

    /** No such question, or it belongs to another user (indistinguishable). */
    case NotFound = 'not_found';
}
