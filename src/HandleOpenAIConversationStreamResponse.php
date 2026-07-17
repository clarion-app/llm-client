<?php

namespace ClarionApp\LlmClient;

use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\HandleHttpStreamResponse;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use Illuminate\Support\Facades\Log;

class HandleOpenAIConversationStreamResponse extends HandleHttpStreamResponse
{
    public string $buffer = "\n\n";
    public string $reply = "";
    public ?Message $message = null;
    /** Provider-reported usage captured from the stream (OpenAI include_usage final chunk). */
    public array $usage = [];
    private ?MetricsRecorder $metricsRecorder = null;

    public function __construct(?MetricsRecorder $metricsRecorder = null)
    {
        $this->metricsRecorder = $metricsRecorder;
    }

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

            event(new NewConversationMessageEvent($conversation_id, $this->message->id));
            Log::info("Created message ".$this->message->id);
        }

        $this->buffer .= $content;
        $check = explode("\n\ndata: ", $this->buffer);
        while(count($check) > 1)
        {
            $chunk = array_shift($check);
            $this->buffer = implode("\n\ndata: ", $check);
            $json = json_decode($chunk);
            if($json != null)
            {
                // Capture provider usage from the final chunk (OpenAI emits a
                // trailing chunk with populated `usage` and empty `choices`).
                if(isset($json->usage) && $json->usage !== null)
                {
                    $this->usage = (array) $json->usage;
                }
                foreach($json->choices ?? [] as $choice)
                {
                    if(!isset($choice->delta->content)) continue;
                    $this->reply .= $choice->delta->content;
                    event(new UpdateOpenAIConversationResponseEvent($conversation_id, $this->message->id, $this->reply));
                }
            }
        }
    }

    public function finish($conversation_id, $seconds)
    {
        if($this->message == null) return;

        $conversation = Conversation::find($conversation_id);

        // Record LLM usage metrics (fire-and-forget, never throws)
        if ($this->metricsRecorder !== null && $conversation) {
            $attemptGroupId = (string) \Illuminate\Support\Str::uuid();

            // Rebuild the input payload only when the provider omitted input
            // usage and it must be estimated — avoids the cost on the common path.
            $inputText = '';
            if (empty($this->usage['prompt_tokens'])) {
                try {
                    $messages = app(\ClarionApp\LlmClient\Services\AgentLoopService::class)
                        ->buildMessagesPayload($conversation);
                    $inputText = implode("\n", array_map(
                        fn ($m) => is_string($m['content'] ?? null) ? $m['content'] : '',
                        $messages
                    ));
                } catch (\Throwable $e) {
                    $inputText = '';
                }
            }

            $this->metricsRecorder->recordUsage(
                conversationId: $conversation->id,
                userId: (string) $conversation->user_id,
                attemptGroupId: $attemptGroupId,
                providerUsage: $this->usage,
                inputText: $inputText,
                outputText: $this->reply,
                model: $conversation->model,
                providerType: $conversation->effectiveProviderType?->value,
            );
        }

        $this->message->content = $this->reply;
        $this->message->responseTime = $seconds;
        $this->message->update();
        event(new FinishOpenAIConversationResponseEvent($conversation_id, $this->reply));

        if($conversation && $conversation->title == null)
        {
            $titleRequest = new OpenAIGenerateConversationTitleRequest($conversation);
            $titleRequest->sendGenerateConversationTitle();
        }
    }
}
