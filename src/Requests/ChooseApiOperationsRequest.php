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
use ClarionApp\Backend\Models\AppPackage;
use ClarionApp\Backend\Models\ComposerPackage;
use Illuminate\Support\Facades\Log;

class ChooseApiOperationsRequest
{
    protected Conversation $conversation;

    protected $messages = [];

    public function __construct($conversation_id, $packages)
    {
        $this->conversation = Conversation::find($conversation_id);
        $operations = [];
        foreach($packages as $package)
        {
            $operations[$package] = ApiManager::getOperations($package);
        }
        $prompt = "Your task is to choose the most appropriate operation IDs for the command. The list of operations is:\n```";
        $prompt.= json_encode($operations, JSON_PRETTY_PRINT);
        $prompt.= "```\nPlease respond with a call to choose_api_operations with the appropriate parameters.";
        foreach($packages as $package)
        {
            [$org, $name] = explode("/", $package);
            Log::info("ClarionApp\LlmClient\Requests\ChooseApiOperationsRequest: Looking for package $org $name");
            $appPackage = AppPackage::where("organization", $org)
                ->where("name", $name)
                ->first();
            if(!$appPackage)
            {
                continue;
            }
            Log::info("ClarionApp\LlmClient\Requests\ChooseApiOperationsRequest: Found package ".print_r($appPackage->toArray(), 1));

            $composerPackage = ComposerPackage::where("app_id", $appPackage->id)
                ->first();

            if(!$composerPackage)
            {
                continue;
            }
            Log::info("ClarionApp\LlmClient\Requests\ChooseApiOperationsRequest: Found composer package ".print_r($composerPackage->toArray(), 1));
            $backend = $composerPackage->organization."/".$composerPackage->name;
            Log::info("ClarionApp\LlmClient\Requests\ChooseApiOperationsRequest: Looking for backend $backend");
            $customPrompts = ApiManager::getCustomPrompts($backend);
            
            if(!$customPrompts)
            {
                continue;
            }
            Log::info("ClarionApp\LlmClient\Requests\ChooseApiOperationsRequest: Found custom prompts ".print_r($customPrompts, 1));
            
            if(isset($customPrompts->chooseOperations))
            {
                $prompt.= "\nOperations instructions for $package:\n".$customPrompts->chooseOperations;
            }
            
            if(isset($customPrompts->generateApiCall))
            {
                $prompt.= "\nAPI call instructions for $package:\n".$customPrompts->generateApiCall;
            }
        }
        $prompt.= "Return only the function call, nothing else. ";
        $this->addMessage($prompt);
        Message::create([
            "conversation_id"=>$this->conversation->id,
            "responseTime"=>0,
            "user"=>"System",
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

    public function sendChooseOperations()
    {
        $properties = [
            "packages" => [
                "type" => "object",
                "additionalProperties" => [
                    "type" => "array",
                    "items" => [
                        "type" => "string"
                    ]
                ]
            ]
        ];
        $required = ["packages"];
        $functionDescription = "Choose the most appropriate operation IDs for the command. ";
        $functionDescription.= "The packages parameter should be an object where the keys are ";
        $functionDescription.= "the package names, and the value should be an array of operation IDs, like this:\n";
        $functionDescription.= "```json\n";
        $functionDescription.= json_encode([
            "packages" => [
                "package1" => ["operation1", "operation2"],
                "package2" => ["operation3"]
            ]
        ], JSON_PRETTY_PRINT);
        $functionDescription.= "\n```";
        $function = GenerateToolFunction::generateFunction(
            "choose_api_operations",
            $functionDescription,
            GenerateToolFunction::generateParameters($properties, $required)
        );

        $newConversation = new \stdClass();
        //$newConversation->temperature = 1.0;
        $newConversation->model = $this->conversation->model;
        $newConversation->stream = false;
        $newConversation->tools = array($function);
        // $newConversation->response_format = ["type" => "json_object"];
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
        Log::info("ClarionApp\LlmClient\Requests\ChooseApiOperationsRequest: Sending request to ".$request->url);
        SendHttpRequest::dispatch($request, "ClarionApp\LlmClient\Responses\HandleChooseApiOperationsResponse", $this->conversation->id);
    }
}
