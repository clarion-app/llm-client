<?php

namespace Tests\RealDatabase;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\GeneratorConfig;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\DeterministicEmbedder;
use Tests\Integration\Harness\LaneRule;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\RequestLane;
use Tests\Integration\Harness\ResponseScript;
use Tests\Integration\Harness\ScriptedTransport;

/**
 * What recording checkpoints costs an uninterrupted task, on the engine
 * production runs.
 *
 * The same fifty-step task (each step makes one effectful call and one
 * read) runs five times with checkpointing on and five times with it off
 * (`llm-client.resume.enabled = false`), interleaved so that drift in the
 * machine's load falls on both. A real model call takes seconds, so a step's
 * time is mostly the model's; each step is charged one second for it (added
 * to the measured time, not waited for) and the checkpoint's own writes are
 * the difference. The median per-step time with checkpointing may be at most
 * 5 % over the median without.
 *
 * When the bound is missed the message prints both medians. Do not loosen
 * the bound: rerun on an idle machine and, if it still misses, compare
 * against a `main` worktree, as the task-budget benchmark was, to see how much of
 * the difference is this feature's.
 */
#[Group('real-db')]
class ResumeOverheadBenchmarkTest extends RealDatabaseTestCase
{
    private const STEPS = 50;

    private const RUNS_EACH = 5;

    /**
     * What one model call takes in production, in seconds: the lowest of
     * "seconds". It is added to each step's measured time rather than waited
     * for, so the ten runs take a minute and not twenty. What is measured is
     * the time the task's own work and the checkpoint's writes take.
     */
    private const MODEL_SECONDS = 1.0;

    /** The added time allowed, as a fraction of the time without. */
    private const BOUND = 0.05;

    #[Test]
    public function checkpointing_adds_at_most_five_percent_to_a_fifty_step_task(): void
    {
        $this->assertReady();
        $this->prepare();

        // One run of each, unmeasured: the first run pays for autoloading,
        // the container and the connection.
        $this->runTask(true);
        $this->runTask(false);

        $with = [];
        $without = [];
        for ($i = 0; $i < self::RUNS_EACH; $i++) {
            // Alternate which comes first.
            if ($i % 2 === 0) {
                $with[] = $this->runTask(true);
                $without[] = $this->runTask(false);
            } else {
                $without[] = $this->runTask(false);
                $with[] = $this->runTask(true);
            }
        }

        // Per step, with the model's time added to both.
        $medianWith = $this->median($with) / self::STEPS + self::MODEL_SECONDS;
        $medianWithout = $this->median($without) / self::STEPS + self::MODEL_SECONDS;
        $addedSeconds = $medianWith - $medianWithout;
        $added = $addedSeconds / $medianWithout;

        fwrite(STDERR, sprintf(
            "\n[resume overhead] per step (with a %.1f s model call): %.2f ms with checkpoints, %.2f ms without (%+.2f %%, %+.2f ms); task seconds with %s, without %s\n",
            self::MODEL_SECONDS,
            $medianWith * 1000,
            $medianWithout * 1000,
            $added * 100,
            $addedSeconds * 1000,
            json_encode(array_map(fn ($s) => round($s, 2), $with)),
            json_encode(array_map(fn ($s) => round($s, 2), $without))
        ));

        // The runs must have been what they claim to be: the task ran all
        // its steps, checkpointed in one mode and not in the other.
        $this->assertSame(
            self::RUNS_EACH + 1,
            DB::table('agent_runs')->where('kind', 'interactive')->whereNotNull('checkpoint_version')->count(),
            'every run with checkpointing on wrote checkpoints'
        );
        $this->assertSame(
            self::RUNS_EACH + 1,
            DB::table('agent_runs')->where('kind', 'interactive')->whereNull('checkpoint_version')->count(),
            'no run with checkpointing off wrote any'
        );
        $this->assertSame(
            0,
            DB::table('agent_runs')->where('kind', 'interactive')->where('step_count', '<', self::STEPS)->count(),
            'every run took all of its steps'
        );

        $this->assertLessThanOrEqual(
            self::BOUND,
            $added,
            sprintf(
                'Checkpointing adds %.2f %% to a step (bound %.0f %%): median %.2f ms per step with it, %.2f ms without, '
                .'counting a %.1f s model call in each. Task seconds with: %s; without: %s. Rerun on an idle machine, and if it '
                .'still misses, A/B against a `main` worktree rather than loosening the bound.',
                $added * 100,
                self::BOUND * 100,
                $medianWith * 1000,
                $medianWithout * 1000,
                self::MODEL_SECONDS,
                json_encode($with),
                json_encode($without)
            )
        );
    }

    /** The seconds one run of the whole task took. */
    private function runTask(bool $checkpointing): float
    {
        $this->app['config']->set('llm-client.resume.enabled', $checkpointing);

        $conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userId,
            'server_id' => $this->serverId,
            'model' => 'gpt-4o',
            'title' => 'Benchmark task',
            'character' => 'Assistant',
        ]);

        // A fresh scope for each run, as a queue worker gives each job.
        $this->app->forgetScopedInstances();

        $began = microtime(true);
        $this->app->make(AgentLoopService::class)->run($conversation, 'Run the benchmark task.');

        return microtime(true) - $began;
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);

        return $values[intdiv(count($values), 2)];
    }

    private string $userId = '';

    private string $serverId = '';

    private function prepare(): void
    {
        $config = $this->app['config'];
        $config->set('llm-client.run_trace.enabled', true);
        // The task's length is the point; neither its iteration bound nor
        // its step ceiling is.
        $config->set('llm-client.agent_loop.max_iterations', 200);
        $config->set('llm-client.memory.embedding.dimension', 8);
        // Fifty steps of history fit the model's window; nothing is dropped or summarised.
        $config->set('llm-client.condensation.enabled', false);

        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Benchmark User',
            'email' => 'benchmark@example.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
        $this->userId = $user->id;

        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Benchmark Server',
            'provider_type' => 'openai',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test-token',
        ]);
        $this->serverId = $server->id;
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $user->id, $server->id, 'gpt-4o');

        // The model: step n is what it answers when n - 1 steps are in the
        // conversation, after a fixed delay.
        $script = new ResponseScript();
        $script->addRule(new LaneRule(
            lane: RequestLane::AgentTurn,
            predicate: fn (CapturedPayload $payload) => true,
            respond: function (CapturedPayload $payload) {
                // The latest step shown, whatever older ones the window dropped.
                $steps = 0;
                foreach ($payload->messages as $message) {
                    foreach (($message['tool_calls'] ?? []) as $call) {
                        if (preg_match('/^b(\d+)_write$/', (string) ($call['id'] ?? ''), $found) === 1) {
                            $steps = max($steps, (int) $found[1]);
                        }
                    }
                }
                if ($steps >= self::STEPS) {
                    return (new ResponseScript())->finalAnswer('The benchmark task is complete.')->usage(10, 10)->steps[0];
                }
                $n = $steps + 1;

                return (new ResponseScript())->toolRequests([
                    ['id' => "b{$n}_write", 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write', 'parameters' => ['body' => ['n' => $n]]]],
                    // A step of operations alone that all succeed ends the task early.
                    ['id' => "b{$n}_apps", 'name' => 'list_applications', 'arguments' => []],
                ])->usage(10, 10)->steps[0];
            },
            label: 'benchmark-task',
        ));
        $transport = new ScriptedTransport($script, new DeterministicEmbedder(8));
        $this->app->bind('llm-client.http_handler', fn () => $transport->handlerStack());

        $this->app->singleton(
            McpToolExecutor::class,
            fn () => new McpToolExecutor($this->app->make(McpToolRegistry::class), null, fn ($user) => 'benchmark-token-'.$user->id),
        );
        $document = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Ledger API', 'version' => '1.0.0'],
            'paths' => [
                '/ledger/write' => ['post' => ['operationId' => 'ledger.write', 'summary' => 'Write to the ledger', 'parameters' => [], 'responses' => ['200' => ['description' => 'Success']]]],
            ],
        ];
        $this->app->instance(Generator::class, new class($document) extends Generator
        {
            public function __construct(private array $document)
            {
            }

            public function __invoke(?GeneratorConfig $config = null)
            {
                return $this->document;
            }
        });
        $catalogue = new OperationCatalogue();
        $catalogue->seed($document);
        $catalogue->fakeHostApi([
            '/ledger/write' => fn () => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
            '/chat/completions' => fn () => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(['title' => 'Benchmark'])]]]], 200, ['Content-Type' => 'application/json']),
        ]);
    }
}
