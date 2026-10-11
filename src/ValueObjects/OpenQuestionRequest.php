<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;

/**
 * A pause site's request to open a question for a validated `ask_user`
 * call. `resumeMode` is the loop path the run paused on (`stream` or
 * `sync`); `preamble` is the assistant text to show above the question
 * (only on the sync path, where that text is otherwise never shown).
 */
final readonly class OpenQuestionRequest
{
    public const RESUME_STREAM = 'stream';
    public const RESUME_SYNC = 'sync';

    /**
     * @param  list<array{tool_call_id: string, content: string}>  $pendingToolResults
     */
    public function __construct(
        public Conversation $conversation,
        public AskUserRequest $request,
        public ?string $runId,
        public ?string $stepId,
        public ?string $actionId,
        public Message $pausedMessage,
        public string $toolCallId,
        public string $resumeMode,
        public ?string $preamble,
        public array $pendingToolResults,
        public int $iteration,
    ) {
    }
}
