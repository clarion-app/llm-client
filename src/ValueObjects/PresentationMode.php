<?php

namespace ClarionApp\LlmClient\ValueObjects;

/** How a question was presented on its channel. */
enum PresentationMode: string
{
    /** With its choices as controls (buttons, a form). */
    case Rich = 'rich';

    /** As the plain-text question, answered by typing. */
    case PlainText = 'plain_text';
}
