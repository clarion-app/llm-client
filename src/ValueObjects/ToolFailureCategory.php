<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Categorizes tool invocation failures for reliability tracking.
 */
enum ToolFailureCategory: string
{
    case Timeout = 'timeout';
    case ConnectionFailure = 'connection_failure';
    case AuthenticationFailure = 'authentication_failure';
    case InvalidInput = 'invalid_input';
    case ServerError = 'server_error';
    case Other = 'other';

    /**
     * Classify an exception into a failure category.
     */
    public static function fromException(\Throwable $e): self
    {
        if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
            return self::ConnectionFailure;
        }

        if ($e instanceof \GuzzleHttp\Exception\ServerException || ($e->getCode() >= 500 && $e->getCode() < 600)) {
            return self::ServerError;
        }

        if ($e instanceof \GuzzleHttp\Exception\ClientException) {
            $code = $e->getCode();
            if ($code === 401 || $code === 403) {
                return self::AuthenticationFailure;
            }
            if ($code === 400 || $code === 422) {
                return self::InvalidInput;
            }
        }

        return self::fromErrorMessage($e->getMessage());
    }

    /**
     * Classify a failure from an error message string.
     *
     * Used when a tool reports failure via a returned error payload (e.g. the
     * agent loop's meta tools return JSON containing an "error" key) rather than
     * by throwing, so no exception object is available to inspect.
     */
    public static function fromErrorMessage(string $message): self
    {
        $m = strtolower($message);

        if (str_contains($m, 'timeout') || str_contains($m, 'timed out')) {
            return self::Timeout;
        }
        if (str_contains($m, 'could not connect') || str_contains($m, 'connection refused')
            || str_contains($m, 'connection failed') || str_contains($m, 'could not resolve')) {
            return self::ConnectionFailure;
        }
        if (str_contains($m, 'unauthorized') || str_contains($m, 'forbidden')
            || str_contains($m, 'authentication') || str_contains($m, '401') || str_contains($m, '403')) {
            return self::AuthenticationFailure;
        }
        if (str_contains($m, 'required') || str_contains($m, 'invalid') || str_contains($m, 'must be')
            || str_contains($m, 'unknown operation') || str_contains($m, 'unknown tool')
            || str_contains($m, 'not found') || str_contains($m, '400') || str_contains($m, '422')) {
            return self::InvalidInput;
        }
        if (str_contains($m, 'server error') || str_contains($m, 'not available')
            || str_contains($m, '500') || str_contains($m, '502') || str_contains($m, '503')) {
            return self::ServerError;
        }

        return self::Other;
    }
}
