<?php

namespace ClarionApp\LlmClient\Exceptions;

class SchemaValidationError extends \RuntimeException
{
    private array $violations;
    private string $rawContent;
    private ?string $strippedContent;
    private array|string $schema;
    private int $retryAttempt;
    private int $maxRetries;

    public function __construct(
        string $message,
        array $violations = [],
        string $rawContent = '',
        ?string $strippedContent = null,
        $schema = [],
        int $retryAttempt = 0,
        int $maxRetries = 0
    ) {
        parent::__construct($message);

        $this->violations = $violations;
        $this->rawContent = $rawContent;
        $this->strippedContent = $strippedContent;
        $this->schema = $schema;
        $this->retryAttempt = $retryAttempt;
        $this->maxRetries = $maxRetries;
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    public function getRawContent(): string
    {
        return $this->rawContent;
    }

    public function getStrippedContent(): ?string
    {
        return $this->strippedContent;
    }

    public function getSchema(): array|string
    {
        return $this->schema;
    }

    public function getRetryAttempt(): int
    {
        return $this->retryAttempt;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function isRetryExhausted(): bool
    {
        return $this->maxRetries > 0 && $this->retryAttempt >= $this->maxRetries;
    }

    /**
     * Return a new instance with updated retry info.
     */
    public function withRetryInfo(int $retryAttempt, int $maxRetries): self
    {
        $clone = clone $this;
        $clone->retryAttempt = $retryAttempt;
        $clone->maxRetries = $maxRetries;
        return $clone;
    }
}
