<?php

namespace ClarionApp\LlmClient;

use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\ServerGroup;

class OpenAIConversationStreamRequest
{
    protected Conversation $conversation;

    protected $messages = [];

    public function __construct(Conversation $conversation)
    {
        $this->conversation = $conversation;
        $this->messages = Message::where('conversation_id', $conversation->id)->orderBy('created_at')->get()->toArray();
    }

    public function addMessage($content)
    {
        array_push($this->messages, Message::create([
            "conversation_id"=>$this->conversation->id,
            "responseTime"=>0,
            "user"=>"User",
            "role"=>"user",
            "content"=>$content
        ])->toArray());
    }

    public function sendConversation()
    {
        $newConversation = new \stdClass();
//        $newConversation->max_tokens = 4096; // add this field to conversation table
        $newConversation->temperature = 1.0;
        $newConversation->model = $this->conversation->model;
        $newConversation->stream = true;
        $newConversation->messages = array();
        foreach($this->messages as $message)
        {
            $m = new \stdClass;
            $m->content = $message['content'];
            $m->role = $message['role'];
            array_push($newConversation->messages, $m);
        }

        $group = ServerGroup::find($this->conversation->server_group_id);
        $server = $group->servers->random();

        $request = new HttpRequest();
        $request->url = $server->server_url."/v1/chat/completions";
        $request->method = "POST";
        $request->headers = [
            'Content-type'=>'application/json',
            'Accept'=>'application/json',
            'Authorization'=>'Bearer '.$group->token
        ];
        $request->body = $newConversation;
        SendHttpStreamRequest::dispatch($request, "ClarionApp\LlmClient\HandleOpenAIConversationStreamResponse", $this->conversation->id);
    }
}
