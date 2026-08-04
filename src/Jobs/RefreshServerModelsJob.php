<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Events\ServerModelsRefreshed;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\ServerStatus;
use ClarionApp\LlmClient\Services\EndpointResolver;
use ClarionApp\LlmClient\ValueObjects\Operation;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that fetches the model list for a server,
 * classifies the outcome, reconciles the llm_models table,
 * and writes the result to the llm_server_statuses table.
 *
 * Six classification categories:
 * 1. 2xx with >= 1 model → models_updated / reachable
 * 2. 2xx with 0 models   → zero_models / reachable
 * 3. 401 or 403          → auth_rejected / auth_rejected
 * 4. Other 4xx/5xx       → http_error / unreachable
 * 5. Connect/DNS/TLS/timeout → unreachable / unreachable
 * 6. Job failed()        → unreachable / unreachable (terminal row)
 */
class RefreshServerModelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 45;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $serverId,
        public readonly ?string $triggeredBy = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EndpointResolver $resolver): void
    {
        $server = Server::find($this->serverId);
        if (!$server) {
            Log::warning('RefreshServerModelsJob: Server not found', [
                'server_id' => $this->serverId,
            ]);
            return;
        }

        // Upsert the status row (or update existing).
        $existingStatus = ServerStatus::where('server_id', $this->serverId)->first();
        $status = ServerStatus::updateOrCreate(
            ['server_id' => $this->serverId],
            [
                'connection_status' => $existingStatus ? $existingStatus->connection_status : 'never_checked',
                'triggered_by' => $this->triggeredBy,
                'refresh_started_at' => now(),
                'refresh_finished_at' => null,
            ]
        );

        // Derive URL and headers from the resolver.
        $url = $resolver->urlFor($server, Operation::Models);
        $headers = $resolver->headersFor($server, Operation::Models);

        try {
            $client = app(Client::class);

            $response = $client->get($url, [
                'headers' => $headers,
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            $this->classifyHttpResponse($status, $statusCode, $body, $server);

        } catch (ConnectException $e) {
            // Category 5: Connection/DNS/TLS/timeout failure.
            $this->classifyUnreachable($status, $e->getMessage());

        } catch (RequestException $e) {
            // If we got a response, classify it; otherwise treat as unreachable.
            $response = $e->getResponse();
            if ($response !== null) {
                $statusCode = $response->getStatusCode();
                $body = json_decode($response->getBody()->getContents(), true) ?? [];
                $this->classifyHttpResponse($status, $statusCode, $body, $server);
            } else {
                $this->classifyUnreachable($status, $e->getMessage());
            }

        } catch (\Throwable $e) {
            $this->classifyUnreachable($status, $e->getMessage());
        }
    }

    /**
     * Classify an HTTP response and update the status row.
     */
    private function classifyHttpResponse(
        ServerStatus $status,
        int $statusCode,
        ?array $body,
        Server $server,
    ): void {
        // Category 3: 401/403 → auth_rejected.
        if ($statusCode === 401 || $statusCode === 403) {
            $status->update([
                'connection_status' => 'auth_rejected',
                'last_outcome' => 'auth_rejected',
                'last_error' => $this->extractErrorMessage($body),
                'model_count' => 0,
                'refresh_finished_at' => now(),
            ]);
            $this->dispatchRefreshedEvent($status);
            return;
        }

        // Category 4: Other 4xx/5xx → http_error.
        if ($statusCode >= 400) {
            $status->update([
                'connection_status' => 'unreachable',
                'last_outcome' => 'http_error',
                'last_error' => sprintf('HTTP %d: %s', $statusCode, $this->extractErrorMessage($body)),
                'model_count' => 0,
                'refresh_finished_at' => now(),
            ]);
            $this->dispatchRefreshedEvent($status);
            return;
        }

        // Category 1 & 2: 2xx success — reconcile models.
        $models = $body['data'] ?? [];
        $this->reconcileModels($server, $models);

        $modelCount = count($models);

        if ($modelCount === 0) {
            // Category 2: zero models.
            $status->update([
                'connection_status' => 'reachable',
                'last_outcome' => 'zero_models',
                'last_error' => null,
                'model_count' => 0,
                'refresh_finished_at' => now(),
            ]);
        } else {
            // Category 1: models updated.
            $status->update([
                'connection_status' => 'reachable',
                'last_outcome' => 'models_updated',
                'last_error' => null,
                'model_count' => $modelCount,
                'refresh_finished_at' => now(),
            ]);
        }
        $this->dispatchRefreshedEvent($status);
    }

    /**
     * Classify as unreachable (connection failure).
     */
    private function classifyUnreachable(ServerStatus $status, string $errorMessage): void
    {
        $status->update([
            'connection_status' => 'unreachable',
            'last_outcome' => 'unreachable',
            'last_error' => $errorMessage,
            'model_count' => 0,
            'refresh_finished_at' => now(),
        ]);
        $this->dispatchRefreshedEvent($status);
    }

    /**
     * Reconcile llm_models: create missing, soft-delete removed.
     */
    private function reconcileModels(Server $server, array $models): void
    {
        $remoteNames = [];
        foreach ($models as $model) {
            $name = $model['id'] ?? null;
            if ($name === null) continue;
            $remoteNames[] = $name;

            // Create if missing.
            if (!LanguageModel::where('server_id', $server->id)
                ->where('name', $name)
                ->first()
            ) {
                LanguageModel::create([
                    'server_id' => $server->id,
                    'name' => $name,
                ]);
            }
        }

        // Soft-delete removed models.
        LanguageModel::where('server_id', $server->id)
            ->whereNotIn('name', $remoteNames)
            ->delete();
    }

    /**
     * Extract a human-readable error message from the response body.
     */
    private function extractErrorMessage(?array $body): ?string
    {
        if ($body === null) return null;
        return $body['error']['message']
            ?? $body['error']
            ?? json_encode($body);
    }

    /**
     * Dispatch the ServerModelsRefreshed event (only for user-triggered refreshes).
     */
    private function dispatchRefreshedEvent(ServerStatus $status): void
    {
        if ($this->triggeredBy === null) {
            return;
        }

        event(new ServerModelsRefreshed(
            $this->serverId,
            $this->triggeredBy,
        ));
    }

    /**
     * Handle a job that failed and could not be processed.
     * Writes a terminal status row.
     */
    public function failed(\Throwable $exception): void
    {
        $status = ServerStatus::where('server_id', $this->serverId)->first();
        if ($status) {
            $status->update([
                'connection_status' => 'unreachable',
                'last_outcome' => 'unreachable',
                'last_error' => $exception->getMessage(),
                'refresh_finished_at' => now(),
            ]);
        } else {
            ServerStatus::create([
                'server_id' => $this->serverId,
                'connection_status' => 'unreachable',
                'last_outcome' => 'unreachable',
                'last_error' => $exception->getMessage(),
                'model_count' => 0,
                'refresh_finished_at' => now(),
                'triggered_by' => $this->triggeredBy,
            ]);
        }

        Log::error('RefreshServerModelsJob failed', [
            'server_id' => $this->serverId,
            'error' => $exception->getMessage(),
        ]);
    }
}
