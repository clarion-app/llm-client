<?php

namespace ClarionApp\LlmClient;

use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\Jobs\SendHttpRequest;
use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Server;

class OpenAIModelsRequest
{
    public function getLanguageModels($server_id)
    {
        $server = Server::find($server_id);

        $request = new HttpRequest();
        $request->url = $server->server_url."/v1/models";
        $request->method = "GET";
        $request->headers = [
            'Content-type'=>'application/json',
            'Accept'=>'application/json',
            'Authorization'=>'Bearer '.$server->token
        ];
        SendHttpRequest::dispatch($request, "ClarionApp\LlmClient\HandleOpenAIModelsResponse", $server_id);
    }
}
