<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\QuestionRefusal;

/**
 * Whether a person can be asked something in a conversation right now, and
 * if not, why. Shared by the agent's ask_user tool and the task-budget
 * ceiling prompt so both follow one rule.
 */
class UserReachability
{
    private const DELEGATION_CHANNEL = 'agent-delegation';

    public function refusal(Conversation $conversation, bool $unattended): ?QuestionRefusal
    {
        if ($conversation->user_id === null || $conversation->user_id === '') {
            return QuestionRefusal::noReachableUser(QuestionRefusal::NO_USER_REASON);
        }

        if ($unattended) {
            return QuestionRefusal::noReachableUser(QuestionRefusal::UNATTENDED_REASON);
        }

        $channel = (string) ($conversation->channel ?? 'web');

        if ($channel === self::DELEGATION_CHANNEL) {
            $rootId = DelegationService::chainRootConversationId((string) $conversation->id);

            if ($rootId !== (string) $conversation->id
                && AgentQuestion::where('pending_conversation_id', $rootId)->exists()
            ) {
                return QuestionRefusal::alreadyPending();
            }

            return QuestionRefusal::noReachableUser(QuestionRefusal::HELPER_REASON);
        }

        if (!app(ConversationChannelRegistry::class)->has($channel)) {
            return QuestionRefusal::noReachableUser("Questions cannot be delivered on the '{$channel}' channel.");
        }

        return null;
    }
}
