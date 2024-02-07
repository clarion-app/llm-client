<?php

namespace ClarionApp\LlmClient;

use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\Jobs\SendHttpRequest;
use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\ServerGroup;

class OpenAIModelsRequest
{
    public function getLanguageModels($server_group_id)
    {
        $group = ServerGroup::find($server_group_id);
        $server = $group->servers->random();

        $request = new HttpRequest();
        $request->url = $server->server_url."/v1/models";
        $request->method = "GET";
        $request->headers = [
            'Content-type'=>'application/json',
            'Accept'=>'application/json',
            'Authorization'=>'Bearer '.$group->token
        ];
        SendHttpRequest::dispatch($request, "ClarionApp\LlmClient\HandleOpenAIModelsResponse", $server_group_id);
    }
}
