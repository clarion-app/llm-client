<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The scope vocabulary for conversation_work_ceilings rows and resolution.
 *
 * Two cases, not three: there is no installation-wide axis. A per-
 * conversation work ceiling bounds how much a single conversation can do
 * within one window, not a total installation-wide throughput ceiling, so
 * there is nothing for a third, installation-scoped case to mean.
 */
enum ConversationWorkScope: string
{
    case ConversationDefault = 'conversation_default';
    case Conversation = 'conversation';
}
