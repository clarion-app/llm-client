<?php

namespace ClarionApp\LlmClient\Responses;

use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\HandleHttpResponse;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use Illuminate\Support\Facades\Log;
use ClarionApp\LlmClient\Requests\ChooseApiOperationsRequest;

class HandleChooseApiApplicationsResponse extends HandleHttpResponse
{
    public function handle(Response $response, $conversation_id, $seconds)
    {
        switch($response->status())
        {
            case 200:
                $conversation = Conversation::find($conversation_id);
                foreach($response->object()->choices as $choice)
                {
                    Log::info("ClarionApp\LlmClient\HandleChooseApiApplicationsResponse: Received response from LLM");
                    Log::info("    Body:".print_r($response->object(), 1));
                    $message = $choice->message;
                    if(isset($message->tool_calls))
                    {
                        foreach($message->tool_calls as $tool_call)
                        {
                            $f = $tool_call->function;
                            if($f->name == "choose_api_applications")
                            {
                                $result = json_decode($f->arguments);
                                break;
                            }
                        }
                    
                    }
                    else
                    {

                        $cleaned = str_replace("```json", "", $message->content);
                        $cleaned = str_replace("```choose_api_applications(", "", $cleaned);
                        $cleaned = str_replace(")```", "", $cleaned);
                        $cleaned = str_replace("```", "", $cleaned);
                        $result = json_decode($cleaned);
                        if(isset($result->arguments)) $result = $result->arguments;
                    }
                    
                    Message::create([
                        "conversation_id"=>$conversation->id,
                        "responseTime"=>$seconds,
                        "user"=>"Clarion",
                        "role"=>"assistant",
                        "content"=>"```".json_encode($result, JSON_PRETTY_PRINT)."```"
                    ]);
                    usleep(1000000);
                    $next = new ChooseApiOperationsRequest($conversation_id, $result->packages);
                    $next->sendChooseOperations();                    
                }
                break;
            default:
                Log::error("ClarionApp\LlmClient\HandleChooseApplicationsResponse: Unknown response status: ".$response->status());
                Log::error("    Body:".print_r($response->object(), 1));
                break;
        }
    }
}
