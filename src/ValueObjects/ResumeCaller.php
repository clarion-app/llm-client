<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Who is resuming a resolved question. An HTTP request returns a
 * synchronous continuation's reply in its response body; a background job
 * or sweep has nobody waiting, so that reply is pushed through the
 * conversation's channel instead.
 */
enum ResumeCaller: string
{
    case HttpRequest = 'http_request';
    case Background = 'background';
}
