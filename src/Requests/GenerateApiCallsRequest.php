<?php

namespace ClarionApp\LlmClient\Requests;

use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\Jobs\SendHttpRequest;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\GenerateToolFunction;
use ClarionApp\Backend\ApiManager;
use Illuminate\Support\Facades\Log;

class GenerateApiCallsRequest
{
    protected Conversation $conversation;

    protected $messages = [];

    public function __construct($conversation_id, $operationDetails)
    {
        $this->conversation = Conversation::find($conversation_id);
        $calls = [];
        foreach($operationDetails as $package=>$operations)
        {
            foreach($operations as $operation)
            {
                $calls[$operation] = ApiManager::getOperationDetails($operation);
            }
        }
        $prompt = "This is the API documentation:\n```\n";
        $prompt.= json_encode($calls, JSON_PRETTY_PRINT);
        $prompt.= "\n```\n";

        $prompt.= "Please repond with a call to generate_api_call with the appropriate parameters. ";
        $prompt.= "If completing the user's command will take multiple API calls, return the first API ";
        $prompt.= "call with the 'continue' parameter set to true so that additional calls can be chained together.";
        $prompt.= "Return only the function call, nothing else. ";
        
        $this->addMessage($prompt);
        Message::create([
            "conversation_id"=>$this->conversation->id,
            "responseTime"=>0,
            "user"=>"User",
            "role"=>"user",
            "content"=>$prompt
        ]);
    }

    public function addMessage($content)
    {
        array_push($this->messages, [
            "conversation_id"=>"",
            "responseTime"=>0,
            "user"=>"User",
            "role"=>"user",
            "content"=>$content
        ]);
    }

    public function sendRequest()
    {
        $properties = new \stdClass();
        $properties->operationId = array(
            "type" => "string",
            "description" => "The operation ID of the API call"
        );
        $properties->path = array(
            "type" => "string",
            "description" => "The path to the resource"
        );
        $properties->method = array(
            "type" => "string",
            "description" => "The HTTP method of the API call"
        );
        $properties->body = array(
            "type" => "object",
            "description" => "The body of the API call",
        );
        $properties->continue = array(
            "type" => "boolean",
            "description" => "Whether to continue with the next API call"
        );

        $parameters = GenerateToolFunction::generateParameters($properties, ["operationId", "path", "method", "body", "continue"]);
        $function = GenerateToolFunction::generateFunction("generate_api_call", "Generate API call to execute the users command", $parameters);

        $newConversation = new \stdClass();
        //$newConversation->temperature = 0.5;
        $newConversation->model = $this->conversation->model;
        $newConversation->stream = false;
        $newConversation->tools = array($function);
        // $newConversation->response_format = ["type" => "json_object"];;
        $newConversation->messages = array();
        foreach($this->messages as $message)
        {
            $m = new \stdClass;
            $m->content = $message['content'];
            $m->role = $message['role'];
            array_push($newConversation->messages, $m);
        }

        $server = Server::find($this->conversation->server_id);

        $request = new HttpRequest();
        $request->url = $server->server_url;
        $request->method = "POST";
        $request->headers = [
            'Content-type'=>'application/json',
            'Accept'=>'application/json',
            'Authorization'=>'Bearer '.$server->token
        ];
        $request->body = $newConversation;
        Log::info("ClarionApp\LlmClient\GenerateApiCallsRequest: Sending request to ".$request->url);
        SendHttpRequest::dispatch($request, "ClarionApp\LlmClient\Responses\HandleGenerateApiCallsResponse", $this->conversation->id);
    }
}
