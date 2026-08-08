<?php

namespace ClarionApp\LlmClient;

use Illuminate\Http\Client\Response;
use ClarionApp\HttpQueue\Jobs\SendHttpRequest;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\EndpointResolver;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\Operation;

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
        $resolver = app(EndpointResolver::class);

        $request = new HttpRequest();
        $request->url = $resolver->urlFor($server, Operation::TitleGeneration);
        $request->method = "POST";
        $request->headers = $resolver->headersFor($server, Operation::TitleGeneration);
        $request->body = $newConversation;

        $dispatch = fn () => SendHttpRequest::dispatch(
            $request,
            "ClarionApp\LlmClient\HandleOpenAIGenerateConversationTitleResponse",
            $this->conversation->id
        );

        $userId = $this->conversation->user_id;

        // Known deviation, recorded rather than papered over: dispatch() only
        // ENQUEUES, and the model call itself is made later by a job in the
        // separate clarion-app/http-queue package. So this one path is admitted
        // at enqueue rather than at dequeue — the shape deferred work
        // deliberately rejects, because a backlog accumulated just under a
        // ceiling would otherwise drain straight through it.
        //
        // Accepted here because it is bounded: one short call, dispatched and
        // executed in the same window, not a queue that can grow. Closing it
        // properly means gating inside http-queue, which this package cannot
        // do. Nothing in this file or its tests claims dequeue-time evaluation
        // for this path.
        if ($userId === null) {
            // traceSystemRun()'s $userId is non-nullable, so an ownerless
            // conversation cannot go through the funnel. The gate still runs:
            // a null user means the installation ceiling alone is evaluated.
            app(BudgetGate::class)->admit(
                null,
                BudgetWorkKind::SystemInitiated,
                $this->conversation->id,
                'title_generation',
            );

            $dispatch();

            return;
        }

        app(RunTraceRecorder::class)->traceSystemRun(
            'title_generation',
            (string) $userId,
            $this->conversation->id,
            $dispatch,
        );
    }
}
