<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Models\ServerGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendServerRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $serverGroup;
    protected $path;
    protected $method;
    protected $body;

    public function __construct(ServerGroup $serverGroup, $path, $method, $body)
    {
        $this->serverGroup = $serverGroup;
        $this->path = $path;
        $this->method = $method;
        $this->body = $body;
    }

    public function handle()
    {
        $server = $this->serverGroup->servers->random();

        $fullUrl = $server->server_url . $this->path; 

        switch (strtolower($this->method)) {
            case 'post':
                $response = Http::post($fullUrl, $this->body);
                break;
            case 'put':
                $response = Http::put($fullUrl, $this->body);
                break;
            case 'patch':
                $response = Http::patch($fullUrl, $this->body);
                break;
            case 'delete':
                $response = Http::delete($fullUrl, $this->body);
                break;
            default:
                $response = Http::get($fullUrl);
        }

        \Log::info('Server Response:', ['response' => $response->body()]);
    }
}
