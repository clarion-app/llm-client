<?php

namespace ClarionApp\LlmClient\ValueObjects;

/** Why a question was presented as plain text rather than richly. */
enum PresentationFallbackReason: string
{
    /** The channel cannot present options (or has no adapter at all). */
    case ChannelLacksOptions = 'channel_lacks_options';

    /** The question has more choices than the channel can show. */
    case TooManyOptions = 'too_many_options';

    /** The channel failed to send the rich presentation. */
    case RichSendRejected = 'rich_send_rejected';
}
