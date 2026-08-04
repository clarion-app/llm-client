<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * LLM operations that map to API endpoints.
 *
 * Backed string enum for type-safe operation identification across
 * provider families and endpoint derivation.
 */
enum Operation: string
{
    case Chat             = 'chat';
    case ChatStream       = 'chat_stream';
    case Embeddings       = 'embeddings';
    case Models           = 'models';
    case TitleGeneration  = 'title_generation';
}
