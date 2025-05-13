<?php

namespace ClarionApp\LlmClient;

use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\HandleHttpResponse;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use Illuminate\Support\Facades\Log;

class HandleOpenAIGenerateConversationTitleResponse extends HandleHttpResponse
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
                    $result = json_decode($message->content);
                    if (isset($result->title)) {
                        $conversation->title = $result->title;
                        $conversation->save();
                        Log::info("ClarionApp\LlmClient\HandleOpenAIConversationResponse: Title generated: ".$result->title);
                    } else {
                        Log::error("ClarionApp\LlmClient\HandleOpenAIConversationResponse: No title found in response");
                        Log::error("    Body:".print_r($response->object(), 1));
                    }
                    
                }
                break;
            default:
                Log::error("ClarionApp\LlmClient\HandleOpenAIConversationResponse: Unknown response status: ".$response->status());
                Log::error("    Body:".print_r($response->object(), 1));
                break;
        }
    }
}
