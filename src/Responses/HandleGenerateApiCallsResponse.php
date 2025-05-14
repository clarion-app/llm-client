<?php

namespace ClarionApp\LlmClient\Responses;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\HandleHttpResponse;
use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\HttpQueue\Jobs\SendHttpRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\GenerateToolFunction;
use Illuminate\Support\Facades\Log;
use ClarionApp\Backend\Models\User;

class HandleGenerateApiCallsResponse extends HandleHttpResponse
{
    protected $messages = [];
    protected Conversation $conversation;

    public function handle(Response $response, $conversation_id, $seconds)
    {
        switch($response->status())
        {
            case 200:
                $this->conversation = Conversation::find($conversation_id);
                foreach($response->object()->choices as $choice)
                {
                    Log::info("ClarionApp\LlmClient\HandleGenerateApiCallsResponse: Received response from LLM");
                    Log::info("    Body:".print_r($response->object(), 1));
                    $message = $choice->message;
                    if(isset($message->tool_calls))
                    {
                        foreach($message->tool_calls as $tool_call)
                        {
                            $f = $tool_call->function;
                            if($f->name == "generate_api_call")
                            {
                                $result = json_decode($f->arguments);
                                break;
                            }
                        }
                    }
                    else
                    {
                        $cleaned = str_replace("```json", "", $message->content);
                        $cleaned = str_replace("```", "", $cleaned);
                        $result = json_decode($cleaned);
                        if(isset($result->function)) $result = $result->function;
                        if(isset($result->arguments)) $result = $result->arguments;
                    }
                    Message::create([
                        "conversation_id"=>$this->conversation->id,
                        "responseTime"=>$seconds,
                        "user"=>"Clarion",
                        "role"=>"assistant",
                        "content"=>"```".json_encode($result, JSON_PRETTY_PRINT)."```"
                    ]);

                    usleep(1000000);
                    $user = User::find($this->conversation->user_id);
                    $accessToken = $user->createToken('CommandCall')->accessToken;
                    Log::info("ClarionApp\LlmClient\HandleGenerateApiCallsResponse. Result: ".print_r($result, 1));
                    $parts = explode("/", stripslashes($result->path));
                    array_unshift($parts, "api");
                    array_unshift($parts, env("APP_URL"));
                    // remove empty elements from the array
                    $parts = array_filter($parts);

                    $path = implode("/", $parts);
                    Log::info("ClarionApp\LlmClient\HandleGenerateApiCallsResponse: Sending API call: ".$result->method." ".$path);
                    $response = Http::withHeaders([
                        "Authorization"=>"Bearer ".$accessToken,
                        "Accept"=>"application/json"
                    ]);
                    switch(strtolower($result->method))
                    {
                        case "get":
                            $response = $response->get($path)->json();
                            break;
                        case "post":
                            $response = $response->post($path, $result->body)->json();
                            break;
                        case "put":
                            if(!isset($result->body))
                            {
                                $prompt = "You did not provide a body for the PUT request. Try the function call again and please provide a body.";
                                Message::create([
                                    "conversation_id"=>$this->conversation->id,
                                    "responseTime"=>$seconds,
                                    "user"=>"System",
                                    "role"=>"user",
                                    "content"=>$prompt
                                ]);
                                $this->sendRequest();
                                return;
                            }
                            $response = $response->put($path, $result->body)->json();
                            break;
                        case "delete":
                            $response = $response->delete($path)->json();
                            break;
                        default:
                            Log::error("ClarionApp\LlmClient\HandleGenerateApiCallsResponse: Unknown HTTP method: ".$result->method);
                            break;
                    }

                    $prompt = "Results of call to ".$result->operationId."```json\n";
                    $prompt.= json_encode($response, JSON_PRETTY_PRINT);
                    $prompt.= "\n```\n";
                    if($result->continue)
                    {
                        $prompt.= "Please repond with a call to generate_api_call with the appropriate parameters. ";
                        $prompt.= "If completing the user's command will take multiple API calls, return the first API ";
                        $prompt.= "call with the 'continue' parameter set to true so that additional calls can be chained ";
                        $prompt.= "together. You MUST include the body parameter.";
                    }

                    Message::create([
                        "conversation_id"=>$this->conversation->id,
                        "responseTime"=>$seconds,
                        "user"=>"System",
                        "role"=>"user",
                        "content"=>$prompt
                    ]);

                    if($result->continue)
                    {
                        $this->sendRequest();
                    }
                }
                break;
            default:
                Log::error("ClarionApp\LlmClient\HandleGenerateApiCallsResponse: Unknown response status: ".$response->status());
                Log::error("    Body:".print_r($response->object(), 1));
                break;
        }
    }

    public function sendRequest()
    {
        $this->messages = Message::where('conversation_id', $this->conversation->id)->orderBy('created_at')->get()->toArray();

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
            "additionalProperties" => true
        );
        $properties->continue = array(
            "type" => "boolean",
            "description" => "Whether to continue with the next API call"
        );

        $parameters = GenerateToolFunction::generateParameters($properties, ["operationId", "path", "method", "body", "continue"]);
        $function = GenerateToolFunction::generateFunction("generate_api_call", "Generate API call to execute the users command", $parameters);

        $newConversation = new \stdClass();
        //$newConversation->temperature = 1.0;
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
        Log::info("ClarionApp\LlmClient\HandleGenerateApiCallsResponse: Sending request to ".$request->url);
        SendHttpRequest::dispatch($request, "ClarionApp\LlmClient\Responses\HandleGenerateApiCallsResponse", $this->conversation->id);
    }
}
