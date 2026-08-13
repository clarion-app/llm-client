<?php

namespace ClarionApp\LlmClient\Exceptions;

class AgentKindNotFoundException extends \RuntimeException
{
    private string $slug;
    private array $availableSlugs;

    public function __construct(string $slug, array $availableSlugs = [])
    {
        $this->slug = $slug;
        $this->availableSlugs = $availableSlugs;

        $message = sprintf('Agent kind "%s" not found.', $slug);
        if (!empty($availableSlugs)) {
            $message .= sprintf(' Available kinds: %s.', implode(', ', $availableSlugs));
        }

        parent::__construct($message);
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getAvailableSlugs(): array
    {
        return $this->availableSlugs;
    }
}
