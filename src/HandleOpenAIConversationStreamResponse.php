<?php

namespace ClarionApp\LlmClient;

use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\HandleHttpResponse;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use Illuminate\Support\Facades\Log;

class HandleOpenAIConversationStreamResponse extends HandleHttpResponse
{
    public string $body = "";
    public string $reply = "";
    public ?Message $message = null;

    public function handle($content, $conversation_id, $seconds)
    {
        $conversation = Conversation::find($conversation_id);

        if($this->message == null)
        {
            $this->message = Message::create([
                "conversation_id"=> $conversation->id,
                "responseTime"=> 0,
                "user"=>$conversation->character,
                "role"=>"assistant",
                "content"=>""
            ]);

            broadcast(new NewConversationMessageEvent($conversation_id, $this->message->id));
            Log::info("Created message ".$this->message->id);
        }

        $this->body .= $content;
        $check = explode("\n\ndata: ", $this->body);
        while(count($check) > 1)
        {
            $chunk = array_shift($check);
            $this->body = implode("\n\ndata: ", $check);
            $json = json_decode($chunk);
            if($json != null)
            {
                foreach($json->choices as $choice)
                {
                    if(!isset($choice->delta->content)) continue;
                    $this->reply .= $choice->delta->content;
                    broadcast(new UpdateOpenAIConversationResponseEvent($conversation_id, $this->message->id, $this->reply));
                }
            }
        }
    }

    public function finish($conversation_id)
    {
        $this->message->content = $this->reply;
        $this->message->update();
        broadcast(new FinishOpenAIConversationResponseEvent($conversation_id, $this->reply));
    }
}
