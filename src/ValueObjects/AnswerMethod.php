<?php

namespace ClarionApp\LlmClient\ValueObjects;

/** How the user answered a question. */
enum AnswerMethod: string
{
    /** A reply typed into the conversation. */
    case TypedReply = 'typed_reply';

    /** The question's form on the web conversation screen. */
    case WebForm = 'web_form';

    /** An option tapped in a messaging app. */
    case MessagingOption = 'messaging_option';
}
