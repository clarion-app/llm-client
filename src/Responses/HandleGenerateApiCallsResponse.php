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
use ClarionApp\LlmClient\Services\ApiCallValidator;
use Illuminate\Support\Facades\Log;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent;

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
                    $message = $choice->message;
                    $result = null;

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
                        $cleaned = str_replace("generate_api_call(", "", $cleaned);
                        $cleaned = str_replace(")", "", $cleaned);
                        $result = json_decode($cleaned);
                        if(isset($result->function)) $result = $result->function;
                        if(isset($result->arguments)) $result = $result->arguments;
                    }

                    // Handle malformed JSON from LLM
                    if ($result === null || !isset($result->operationId) || !isset($result->method) || !isset($result->path)) {
                        Log::error("ClarionApp\LlmClient\HandleGenerateApiCallsResponse: Malformed JSON from LLM");
                        $errorMessage = Message::create([
                            "conversation_id" => $this->conversation->id,
                            "responseTime" => $seconds,
                            "user" => "System",
                            "role" => "system",
                            "content" => "Error: The LLM returned malformed API call parameters. The call was not executed.",
                        ]);
                        event(new NewConversationMessageEvent($this->conversation->id, $errorMessage->id));
                        event(new FinishOpenAIConversationResponseEvent($this->conversation->id, $errorMessage->content));
                        return;
                    }

                    // Sanitize path to reject traversal patterns
                    $decodedPath = urldecode($result->path);
                    if (str_contains($decodedPath, '../') || str_contains($decodedPath, '..\\')) {
                        Log::warning("ClarionApp\LlmClient\HandleGenerateApiCallsResponse: Path traversal attempt: " . $result->path);
                        $errorMessage = Message::create([
                            "conversation_id" => $this->conversation->id,
                            "responseTime" => $seconds,
                            "user" => "System",
                            "role" => "system",
                            "content" => "Error: The API call path contains traversal patterns and was rejected.",
                        ]);
                        event(new NewConversationMessageEvent($this->conversation->id, $errorMessage->id));
                        event(new FinishOpenAIConversationResponseEvent($this->conversation->id, $errorMessage->content));
                        return;
                    }

                    // Validate API call against OpenAPI docs and denylist
                    $validation = ApiCallValidator::validate($result->operationId, $result->method, $result->path);

                    if ($validation['status'] === ApiCallValidator::STATUS_REJECT) {
                        Log::warning("ClarionApp\LlmClient\HandleGenerateApiCallsResponse: API call rejected: " . $validation['reason']);
                        $errorMessage = Message::create([
                            "conversation_id" => $this->conversation->id,
                            "responseTime" => $seconds,
                            "user" => "System",
                            "role" => "system",
                            "content" => "Error: API call rejected — " . $validation['reason'],
                        ]);
                        event(new NewConversationMessageEvent($this->conversation->id, $errorMessage->id));
                        event(new FinishOpenAIConversationResponseEvent($this->conversation->id, $errorMessage->content));
                        return;
                    }

                    if ($validation['status'] === ApiCallValidator::STATUS_CONFIRM) {
                        // Store pending call as system message with marker
                        $pendingData = json_encode([
                            '__pending_api_call' => true,
                            'operationId' => $result->operationId,
                            'method' => $result->method,
                            'path' => $result->path,
                            'body' => $result->body ?? null,
                            'continue' => $result->continue ?? false,
                        ]);
                        $pendingMessage = Message::create([
                            "conversation_id" => $this->conversation->id,
                            "responseTime" => $seconds,
                            "user" => "System",
                            "role" => "system",
                            "content" => $pendingData,
                        ]);
                        event(new ApiCallConfirmationRequiredEvent(
                            $this->conversation->id,
                            $pendingMessage->id,
                            $result->method,
                            $result->path,
                            $result->body ?? null
                        ));
                        return;
                    }

                    // STATUS_ALLOW — proceed with execution
                    $reply = "```".json_encode($result, JSON_PRETTY_PRINT)."```";
                    $message = Message::create([
                        "conversation_id"=>$this->conversation->id,
                        "responseTime"=>$seconds,
                        "user"=>"Clarion",
                        "role"=>"assistant",
                        "content"=>$reply
                    ]);
                    event(new NewConversationMessageEvent($this->conversation->id, $message->id));
                    event(new FinishOpenAIConversationResponseEvent($this->conversation->id, $reply));

                    $this->executeApiCall($result, $seconds);
                }
                break;
            default:
                Log::error("ClarionApp\LlmClient\HandleGenerateApiCallsResponse: Unknown response status: ".$response->status());
                Log::error("    Body:".print_r($response->object(), 1));
                break;
        }
    }

    public function executeApiCall($result, $seconds)
    {
        $user = User::find($this->conversation->user_id);
        $accessToken = $user->createToken('CommandCall')->accessToken;
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

        $message = Message::create([
            "conversation_id"=>$this->conversation->id,
            "responseTime"=>$seconds,
            "user"=>"System",
            "role"=>"user",
            "content"=>$prompt
        ]);
        event(new NewConversationMessageEvent($this->conversation->id, $message->id));
        event(new FinishOpenAIConversationResponseEvent($this->conversation->id, $prompt));

        if($result->continue)
        {
            $this->sendRequest();
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
        SendHttpRequest::dispatch($request, "ClarionApp\LlmClient\Responses\HandleGenerateApiCallsResponse", $this->conversation->id);
    }
}
