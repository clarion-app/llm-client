<?php

namespace ClarionApp\LlmClient;

use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\Jobs\SendHttpRequest;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;

class OpenAIGenerateConversationTitleRequest
{
    protected Conversation $conversation;

    protected $messages = [];

    public function __construct(Conversation $conversation)
    {
        $this->conversation = $conversation;
        $this->messages = Message::where('conversation_id', $conversation->id)->orderBy('created_at')->get()->toArray();
        $this->addMessage("Generate a title for this conversation. Only respond with a JSON object with a single field called title. The value of the title field should be a string that is a title for the conversation. The title should be short and descriptive, and should not include any personal information or sensitive data. The title should be in English. Do not return extra text or formatting information.");
    }

    public function addMessage($content)
    {
        array_push($this->messages, [
            "conversation_id"=>$this->conversation->id,
            "responseTime"=>0,
            "user"=>"User",
            "role"=>"user",
            "content"=>$content
        ]);
    }

    public function sendGenerateConversationTitle()
    {
        $newConversation = new \stdClass();
//        $newConversation->max_tokens = 4096; // add this field to conversation table
        $newConversation->temperature = 1.0;
        $newConversation->model = $this->conversation->model;
        $newConversation->stream = false;
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
        SendHttpRequest::dispatch($request, "ClarionApp\LlmClient\HandleOpenAIGenerateConversationTitleResponse", $this->conversation->id);
    }
}
