<?php

namespace ClarionApp\LlmClient\ValueObjects;

enum ModelRole: string
{
    case Inference = 'inference';
    case Embedding = 'embedding';
    case Image     = 'image';
    case Judge     = 'judge';

    public function whatBreaksWhenUnassigned(): string
    {
        return match ($this) {
            self::Inference => 'Starting a new conversation without explicitly choosing a model will fail.',
            self::Embedding => 'Semantic memory search and automatic memory retrieval will be unavailable.',
            self::Image     => 'Nothing currently consumes this role — reserved for future image generation.',
            self::Judge     => 'Cases configured for rubric-based judging will be recorded as unjudged instead of scored.',
        };
    }
}
