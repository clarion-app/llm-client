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
use ClarionApp\Backend\Models\User;
use Illuminate\Support\Facades\Log;

class ChooseApiApplicationsRequest
{
    protected Conversation $conversation;

    protected $messages = [];

    public function __construct($user_command)
    {
        $model = LanguageModel::where("name", "mistral-small-2503")->first();
        $this->conversation = Conversation::create([
            "user_id"=>User::first()->id, // TODO: get user from request
            "server_id"=>$model->server_id,
            "model"=>$model->name,
            "character"=>"Clarion",
            "title"=>"Interpret user command",
        ]);
        usleep(500000);
        $packages = json_encode(ApiManager::getPackageDescriptions(), JSON_PRETTY_PRINT);
        $prompt = "You are a helpful assistant. You will be given a command and a list of packages. ";
        $prompt.= "Your task is to choose the most appropriate package or packages for the command. ";
        $prompt.= "The command is: $user_command. The list of packages is: ```$packages```. ";
        $prompt.= "Please respond with a call to choose_api_applications with an array of the full name of packages ";
        $prompt.= "that you think are most appropriate for the command.";
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

    public function sendChooseApplications()
    {
        $properties = [
            "packages" => [
                "type" => "array",
                "items" => [
                    "type" => "string"
                ]
            ]
        ];
        $required = ["packages"];
        $function = GenerateToolFunction::generateFunction(
            "choose_api_applications",
            "Choose the most appropriate package or packages for the command.",
            GenerateToolFunction::generateParameters($properties, $required)
        );
        
        $newConversation = new \stdClass();
//        $newConversation->max_tokens = 4096; // add this field to conversation table
        //$newConversation->temperature = 0.1;
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

        // Log::info("Conversation request: ".json_encode($newConversation, JSON_PRETTY_PRINT));

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
        SendHttpRequest::dispatch($request, "ClarionApp\LlmClient\Responses\HandleChooseApiApplicationsResponse", $this->conversation->id);
    }
}
