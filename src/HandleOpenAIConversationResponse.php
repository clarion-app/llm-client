<?php

namespace ClarionApp\LlmClient;

use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\HandleHttpResponse;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use Illuminate\Support\Facades\Log;

class HandleOpenAIConversationResponse extends HandleHttpResponse
{
    public function handle(Response $response, $conversation_id, $seconds)
    {
        switch($response->status())
        {
            case 200:
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
                break;
            default:
                Log::error("ClarionApp\LlmClient\HandleOpenAIConversationResponse: Unknown response status: ".$response->status());
                Log::error("    Body:".print_r($response->object(), 1));
                break;
        }
    }
}
