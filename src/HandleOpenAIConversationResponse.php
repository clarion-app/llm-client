<?php

namespace ClarionApp\LlmClient;

use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\HandleHttpResponse;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;

class HandleOpenAIConversationResponse extends HandleHttpResponse
{
    public function handle(Response $response, $conversation_id, $seconds)
    {
        $conversation = Conversation::find($conversation_id);
        foreach($response->object()->choices as $choice)
        {
            $message = $choice->message;
            if($message->role == "assistant") $name = $conversation->character;
            else $name = "User";

            Message::create([
                "conversation_id"=>$conversation->id,
                "responseTime"=>$seconds,
                "user"=>$name,
                "role"=>$message->role,
                "content"=>$message->content
            ]);
        }
    }
}
