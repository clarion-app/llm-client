<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;

/**
 * Every write to `agent_questions` goes through this service.
 *
 * Each way a question can leave `pending` — answered, expired, withdrawn,
 * undeliverable — is the single compare-and-set in transition(), so racing
 * resolutions always produce exactly one winner, and only the winner
 * delivers a notice or resumes the run.
 */
class AgentQuestionService
{
    /**
     * Move a pending question to a terminal state, if it is still pending.
     *
     * The winning caller (returns true) sets the state and `resolved_at`,
     * releases the one-pending-per-conversation latch and writes $fields
     * (raw column values). When $setProcessing is true the conversation is
     * marked processing in the same commit, so a second message arriving
     * between this resolution and the resume can never start a concurrent
     * run. A losing caller (returns false) changes nothing.
     *
     * @param  array<string, mixed>  $fields
     */
    private function transition(string $id, QuestionState $to, array $fields, bool $setProcessing): bool
    {
        if ($to === QuestionState::Pending) {
            throw new \InvalidArgumentException('A question can only transition to a terminal state.');
        }

        return DB::transaction(function () use ($id, $to, $fields, $setProcessing) {
            $now = now();

            $affected = DB::table('agent_questions')
                ->where('id', $id)
                ->where('state', QuestionState::Pending->value)
                ->update(array_merge($fields, [
                    'state' => $to->value,
                    'resolved_at' => $now,
                    'pending_conversation_id' => null,
                    'updated_at' => $now,
                ]));

            if ($affected !== 1) {
                return false;
            }

            if ($setProcessing) {
                $conversationId = DB::table('agent_questions')->where('id', $id)->value('conversation_id');
                Conversation::find($conversationId)?->update(['is_processing' => true]);
            }

            return true;
        });
    }
}
