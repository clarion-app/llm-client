<?php

namespace ClarionApp\LlmClient\ValueObjects;

enum ActionType: string
{
    case LlmRequest     = 'llm_request';
    case ToolInvocation = 'tool_invocation';
    case ContextReshape = 'context_reshape';
}
