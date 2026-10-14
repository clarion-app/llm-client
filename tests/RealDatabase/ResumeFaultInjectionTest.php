<?php

namespace Tests\RealDatabase;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\FaultInjector;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/**
 * A long task whose worker dies for real, on the engine production runs.
 *
 * The fast suite stages a dead worker by snapshotting the tables at a fault
 * point and throwing; it cannot show what a process killed by the operating
 * system leaves behind (an open transaction rolled back, a half-written
 * file, a connection dropped mid-statement), nor that the detection sweep and
 * a queue worker really bring the task home. Here worker processes boot the
 * package against the run-created ephemeral database, serve the model from a
 * scripted provider and write one line to a file outside the database for
 * every execution of the effectful operation. A worker is armed through
 * LLM_CLIENT_FAULT_POINT=<point>:<occurrence> and the fault injector sends
 * it SIGKILL at that point. The harness then runs the resume sweep (with the
 * detection window set to one second) and a queue worker until the run is
 * terminal, sometimes killing that worker too.
 *
 * Every trial's task is the same twelve steps (reads, writes, a batch of
 * three helpers, a final reply) and each trial asserts:
 *
 *  - the run is terminal: finished, or stopped with a report;
 *  - it was never restarted from its first step: one run for the task, step
 *    positions strictly increasing with none repeated;
 *  - the file ledger shows every write whose result the conversation holds
 *    as executed exactly once, and every other write at most once, with an
 *    outcome-unknown result in the conversation for the ones that were
 *    in flight;
 *  - one assistant message per step, one final reply, three helper
 *    delegations;
 *
 * and, across the trials, that the sweep brought at least 95 % of the killed
 * runs back within the detection window plus one sweep interval.
 *
 * The trials are random but seeded: the seed is printed in every failure and
 * can be replayed with RESUME_FAULT_SEED=<seed> (RESUME_FAULT_TRIALS=<n>
 * shortens a debugging run, RESUME_FAULT_VERBOSE=1 prints every trial's
 * workers).
 *
 * The points a trial chooses are those the synchronous path reaches. The
 * three streaming-only points (mid_stream, after_claim, during_pause_resume)
 * need a streaming job and a question or ceiling pause; the in-process suite
 * covers them.
 */
#[Group('real-db')]
class ResumeFaultInjectionTest extends RealDatabaseTestCase
{
    /** The detection window the trials run with, in seconds. */
    private const WINDOW_SECONDS = 1;

    /** One sweep interval: the longest the harness leaves between two sweeps. */
    private const SWEEP_INTERVAL_SECONDS = 3.0;

    /** The most workers one trial may start before it is called stuck. */
    private const MAX_WORKERS_PER_TRIAL = 30;

    /** A trial that has not ended after this long is stuck. */
    private const TRIAL_SECONDS = 90;

    /**
     * The points the synchronous path reaches, with the most occurrences a
     * full run is certain to reach (each is a lower bound on what the
     * twelve-step task makes, so an armed point is always reached).
     */
    private const REACHABLE_POINTS = [
        'after_model_recorded' => 12,
        'before_tool_started' => 20,
        'after_tool_started' => 20,
        'after_tool_effect' => 20,
        'after_tool_recorded' => 20,
        'before_step_message' => 11,
        'after_step_message' => 11,
        'after_delegation_row' => 3,
        'during_join_wait' => 1,
    ];

    /** The points reached once per call the task makes outside the helper batch (twenty of them). */
    private const TOOL_POINTS = ['before_tool_started', 'after_tool_started', 'after_tool_effect', 'after_tool_recorded'];

    /**
     * Where the first three writes fall in the order those calls are made
     * (each step's calls in turn): occurrences that kill a worker with an
     * effectful call in flight, before the helper batch shifts the count.
     */
    private const WRITE_OCCURRENCES = [3, 6, 8];

    /** The final reply of the task. */
    private const FINAL_REPLY = 'The trial task is complete.';

    /** Scratch directory holding the worker script, its configuration and the ledgers. */
    private ?string $scratch = null;

    /** @var list<string> the helper agents' ids */
    private array $helperIds = [];

    private string $rootAgentId = '';

    private string $rootAgentVersionId = '';

    private string $userId = '';

    private string $serverId = '';

    protected function tearDown(): void
    {
        $this->removeScratch();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // The trials
    // ---------------------------------------------------------------

    #[Test]
    public function randomized_kills_never_lose_or_repeat_work(): void
    {
        $this->assertReady();

        if (!function_exists('posix_kill')) {
            $this->fail('The posix extension is required to kill worker processes.');
        }

        $seed = (int) (getenv('RESUME_FAULT_SEED') ?: random_int(1, 2_000_000_000));
        $trials = (int) (getenv('RESUME_FAULT_TRIALS') ?: 100);
        mt_srand($seed);

        $this->prepareEnvironment();

        $points = array_keys(self::REACHABLE_POINTS);
        shuffle($points);

        $failures = [];
        $distribution = [];
        $killsFired = 0;
        $secondKillsFired = 0;
        $latencies = [];
        $within = 0;
        $measured = 0;

        for ($trial = 1; $trial <= $trials; $trial++) {
            // Round-robin over a seeded shuffle, so every reachable point is
            // armed in the first nine trials and the occurrence is random.
            $point = $points[($trial - 1) % count($points)];
            $occurrence = mt_rand(1, self::REACHABLE_POINTS[$point]);
            // A kill that lands on a write is the case the ledger exists
            // for: aim the first round of each call point at one, and half
            // of the later rounds.
            if (in_array($point, self::TOOL_POINTS, true) && ($trial <= count($points) || mt_rand(0, 1) === 1)) {
                $occurrence = self::WRITE_OCCURRENCES[mt_rand(0, count(self::WRITE_OCCURRENCES) - 1)];
            }
            $second = mt_rand(1, 100) <= 30
                ? $this->randomSecondKill()
                : null;

            $outcome = $this->runTrial($trial, $point, $occurrence, $second);

            $key = "{$point}:{$occurrence}";
            $distribution[$point] = ($distribution[$point] ?? 0) + 1;
            $killsFired += $outcome['kills'] >= 1 ? 1 : 0;
            $secondKillsFired += $outcome['kills'] >= 2 ? 1 : 0;

            foreach ($outcome['latencies'] as $latency) {
                $measured++;
                $latencies[] = $latency;
                if ($latency <= self::SWEEP_INTERVAL_SECONDS) {
                    $within++;
                }
            }

            foreach ($outcome['failures'] as $failure) {
                $failures[] = "trial {$trial} ({$key}"
                    .($second !== null ? ", then {$second[0]}:{$second[1]}" : '')
                    ."): {$failure}";
            }

            if (count($failures) >= 5) {
                break;
            }
        }

        $this->assertSame(
            [],
            $failures,
            "seed {$seed}: the trials found problems (replay with RESUME_FAULT_SEED={$seed}):\n".implode("\n", array_slice($failures, 0, 10))
        );

        // The armed fault must really have killed workers: a trial whose
        // fault never fired proves nothing about a kill. Every chosen
        // occurrence is one the task is certain to reach.
        $this->assertGreaterThanOrEqual(
            (int) floor($trials * 0.9),
            $killsFired,
            "seed {$seed}: only {$killsFired} of {$trials} trials killed their worker; the armed points are not being reached"
        );
        $this->assertGreaterThan(0, $secondKillsFired, "seed {$seed}: no trial killed the resuming worker too");

        // The sweep brings killed runs back promptly.
        $this->assertGreaterThan(0, $measured, "seed {$seed}: no killed run was resumed, so there is nothing to measure");
        sort($latencies);
        $this->assertGreaterThanOrEqual(
            0.95,
            $within / $measured,
            sprintf(
                'seed %d: only %d of %d killed runs resumed within the detection window plus one sweep interval (%.1f s); '
                .'slowest %.2f s, median %.2f s, kill points %s',
                $seed,
                $within,
                $measured,
                self::SWEEP_INTERVAL_SECONDS,
                end($latencies),
                $latencies[intdiv(count($latencies), 2)],
                json_encode($distribution)
            )
        );

        fwrite(STDERR, sprintf(
            "\n[resume fault injection] seed %d, %d trials, %d killed, %d killed twice, %d of %d resumed in time (median %.2f s, max %.2f s), points %s\n",
            $seed,
            $trials,
            $killsFired,
            $secondKillsFired,
            $within,
            $measured,
            $latencies[intdiv(count($latencies), 2)],
            end($latencies),
            json_encode($distribution)
        ));
    }

    /** @return array{0: string, 1: int} */
    private function randomSecondKill(): array
    {
        $points = array_keys(self::REACHABLE_POINTS);
        $point = $points[mt_rand(0, count($points) - 1)];

        // Early in the resumed work, so it is reached whatever was left to do.
        return [$point, mt_rand(1, min(2, self::REACHABLE_POINTS[$point]))];
    }

    // ---------------------------------------------------------------
    // One trial
    // ---------------------------------------------------------------

    /**
     * @param  array{0: string, 1: int}|null  $second  a kill to arm on the resuming worker
     * @return array{kills: int, latencies: list<float>, failures: list<string>}
     */
    private function runTrial(int $trial, string $point, int $occurrence, ?array $second): array
    {
        $failures = [];
        $kills = [];
        $conversation = $this->makeConversation();
        $ledger = $this->scratchDir()."/ledger-{$trial}.log";
        touch($ledger);

        $started = microtime(true);
        $diagnostics = [];

        $first = $this->worker('start', $conversation->id, $ledger, "{$point}:{$occurrence}");
        $diagnostics[] = $this->describe('start', $first);
        if ($first['killed']) {
            $kills[] = $this->recordKill($conversation->id, $first['ended_at']);
        } elseif ($first['exit'] !== 0) {
            // Neither killed nor finished: the worker itself is broken.
            return ['kills' => 0, 'latencies' => [], 'failures' => ['the start worker failed: '.$this->describe('start', $first)]];
        }

        $secondArmed = $second !== null;
        $workers = 0;

        while (!$this->isTerminal($conversation->id)) {
            if (microtime(true) - $started > self::TRIAL_SECONDS || $workers >= self::MAX_WORKERS_PER_TRIAL) {
                $failures[] = 'the run was not terminal after '.round(microtime(true) - $started).' s and '.$workers.' resume workers: '.implode(' | ', $diagnostics);
                break;
            }

            // The sweep takes a run only once its lease has run out; start the
            // next sweep when that moment comes rather than burning workers.
            $this->waitUntil(end($kills)['lease_until'] ?? null);

            $workers++;
            $arm = $secondArmed ? "{$second[0]}:{$second[1]}" : '';
            $result = $this->worker('resume', $conversation->id, $ledger, $arm);
            $diagnostics[] = $this->describe('resume', $result);

            if ($result['killed']) {
                $kills[] = $this->recordKill($conversation->id, $result['ended_at']);
                // The kill that was armed has fired; later workers run clean.
                $secondArmed = false;
            } elseif ($result['exit'] !== 0) {
                $failures[] = 'a resume worker failed: '.implode(' | ', $diagnostics);
                break;
            }

            usleep(250_000);
        }

        if ($failures === []) {
            $failures = $this->checkTrial($conversation, $ledger);
        }
        if (getenv('RESUME_FAULT_VERBOSE')) {
            fwrite(STDERR, "\ntrial {$trial}: ".implode(' | ', $diagnostics)."\n");
        }
        if ($failures !== []) {
            $failures = [implode('; ', $failures).' ['.implode(' | ', $diagnostics).']'];
        }

        return [
            'kills' => count($kills),
            'latencies' => $this->resumeLatencies($conversation->id, $kills),
            'failures' => $failures,
        ];
    }

    private function waitUntil(?float $moment): void
    {
        if ($moment === null) {
            return;
        }

        $remaining = $moment - microtime(true);
        if ($remaining > 0) {
            usleep((int) (($remaining + 0.02) * 1_000_000));
        }
    }

    /**
     * What the dead worker left: the moment it died and when its run's lease
     * runs out.
     *
     * @return array{at: float, lease_until: float|null}
     */
    private function recordKill(string $conversationId, float $at): array
    {
        $run = DB::table('agent_runs')->where('conversation_id', $conversationId)->where('kind', 'interactive')->first();

        return [
            'at' => $at,
            'lease_until' => $run?->lease_expires_at !== null ? (float) Carbon::parse($run->lease_expires_at, 'UTC')->format('U.u') : null,
        ];
    }

    /**
     * For each kill, how long after the moment the sweep was allowed to take
     * the run (the later of the detection window and the lease) it actually
     * started resuming.
     *
     * @param  list<array{at: float, lease_until: float|null}>  $kills
     * @return list<float>
     */
    private function resumeLatencies(string $conversationId, array $kills): array
    {
        $run = DB::table('agent_runs')->where('conversation_id', $conversationId)->where('kind', 'interactive')->first();
        if ($run === null) {
            return [];
        }

        $events = DB::table('agent_run_resume_events')
            ->where('run_id', $run->id)
            ->where('kind', 'resume_started')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($event) => (float) Carbon::parse($event->created_at, 'UTC')->format('U.u'))
            ->all();

        $latencies = [];
        foreach ($kills as $kill) {
            $eligible = max($kill['at'] + self::WINDOW_SECONDS, $kill['lease_until'] ?? 0.0);
            $next = null;
            foreach ($events as $event) {
                if ($event >= $kill['at']) {
                    $next = $event;
                    break;
                }
            }
            if ($next === null) {
                // Killed after its last act: nothing was left to resume.
                continue;
            }
            $latencies[] = max(0.0, $next - $eligible);
        }

        return $latencies;
    }

    // ---------------------------------------------------------------
    // What every trial asserts
    // ---------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function checkTrial(Conversation $conversation, string $ledgerFile): array
    {
        $failures = [];

        // Terminal: finished, or stopped with a report.
        $runs = DB::table('agent_runs')->where('conversation_id', $conversation->id)->where('kind', 'interactive')->orderBy('started_at')->get();
        if ($runs->count() !== 1) {
            return ["the task has {$runs->count()} runs, expected the one that was resumed"];
        }
        $run = $runs->first();

        $messages = Message::where('conversation_id', $conversation->id)->orderBy('created_at')->get();
        $completed = $run->end_state === 'completed';
        if (!$completed) {
            $reported = $messages->contains(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'task_resume_notice')
                || trim((string) $run->end_reason) !== '';
            if (!$reported) {
                $failures[] = "the run ended {$run->end_state} with no report";
            }
        }

        // Never restarted from the first step.
        $steps = DB::table('agent_run_steps')->where('run_id', $run->id)->orderBy('started_at')->orderBy('position')->get();
        $positions = $steps->pluck('position')->map(fn ($p) => (int) $p)->all();
        if (count($positions) !== count(array_unique($positions))) {
            $failures[] = 'a step position repeats: '.json_encode($positions);
        }
        $sorted = $positions;
        sort($sorted);
        if ($positions !== $sorted) {
            $failures[] = 'step positions are not increasing in the order the steps started: '.json_encode($positions);
        }

        // The ledger, against what the conversation holds.
        $executions = $this->executionsIn($ledgerFile);
        $results = $this->toolResultsOf($messages);
        foreach (self::writeCalls() as $callId => $n) {
            $count = $executions[$n] ?? 0;
            $result = $results[$callId] ?? null;

            if ($count > 1) {
                $failures[] = "write {$n} ({$callId}) executed {$count} times";
            }
            if ($result !== null && !$this->isOutcomeUnknown($result) && $count !== 1) {
                $failures[] = "write {$n} ({$callId}) has a recorded result but the ledger shows {$count} executions";
            }
            if ($completed && $result === null) {
                $failures[] = "the run completed with no result for write {$n} ({$callId})";
            }
            if ($result !== null && $this->isOutcomeUnknown($result) && $count > 1) {
                $failures[] = "write {$n} ({$callId}) is outcome unknown but ran {$count} times";
            }
        }

        // One assistant message per step, one final reply.
        $stepMessages = $messages->filter(fn (Message $m) => $m->role === 'assistant' && !empty($m->tool_data['tool_calls'] ?? null) && ($m->tool_data['kind'] ?? null) === null);
        $firstIds = $stepMessages->map(fn (Message $m) => (string) ($m->tool_data['tool_calls'][0]['id'] ?? ''))->values()->all();
        if (count($firstIds) !== count(array_unique($firstIds))) {
            $failures[] = 'a step has more than one assistant message: '.json_encode($firstIds);
        }
        $finals = $messages->filter(fn (Message $m) => $m->role === 'assistant' && str_contains((string) $m->content, self::FINAL_REPLY))->count();
        if ($finals > 1) {
            $failures[] = "the final reply was written {$finals} times";
        }
        if ($completed) {
            $expected = array_map(fn (array $calls) => $calls[0]['id'], array_values(self::plan()));
            if ($firstIds !== $expected) {
                $failures[] = 'the steps are not exactly the planned ones, in order: got '.json_encode($firstIds);
            }
            if ($finals !== 1) {
                $last = $messages->filter(fn (Message $m) => $m->role === 'assistant')->last();
                $failures[] = "the completed run has {$finals} final replies (the last assistant message reads \"".substr((string) $last?->content, 0, 120).'")';
            }
            $delegations = DB::table('agent_delegations')->where('parent_conversation_id', $conversation->id)->count();
            if ($delegations !== 3) {
                $failures[] = "{$delegations} delegations for the three helper calls";
            }
        }

        return $failures;
    }

    /** @return array<int, int> executions of ledger.write per write number, from the file */
    private function executionsIn(string $ledgerFile): array
    {
        // The ledger's key names the tool and hashes the arguments.
        $byKey = [];
        foreach (range(1, 6) as $n) {
            $byKey['ledger.write::'.sha1(json_encode(['n' => $n]))] = $n;
        }

        $counts = [];
        foreach (file($ledgerFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            $n = is_array($entry) ? ($byKey[$entry['key'] ?? ''] ?? null) : null;
            if ($n !== null) {
                $counts[$n] = ($counts[$n] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @return array<string, array<string, mixed>> the tool results by call id (the last one wins) */
    private function toolResultsOf($messages): array
    {
        $results = [];
        foreach ($messages as $message) {
            foreach (($message->tool_data['tool_results'] ?? []) as $result) {
                if (isset($result['tool_call_id'])) {
                    $results[(string) $result['tool_call_id']] = $result;
                }
            }
        }

        return $results;
    }

    private function isOutcomeUnknown(array $result): bool
    {
        if (!empty($result['outcome_unknown'])) {
            return true;
        }
        $decoded = json_decode((string) ($result['content'] ?? ''), true);

        return is_array($decoded) && !empty($decoded['outcome_unknown']);
    }

    private function isTerminal(string $conversationId): bool
    {
        $state = DB::table('agent_runs')->where('conversation_id', $conversationId)->where('kind', 'interactive')->value('end_state');

        return $state !== null && $state !== 'in_progress';
    }

    // ---------------------------------------------------------------
    // The task
    // ---------------------------------------------------------------

    /**
     * The model's twelve steps: step n is what the model answers when n - 1
     * steps are already in the conversation. Call ids are fixed so a step
     * served again after a resume is the same step.
     *
     * @param  list<string>  $helperIds
     * @return array<int, list<array{id: string, name: string, arguments: array<string, mixed>}>>
     */
    private static function plan(array $helperIds = ['A', 'B', 'C']): array
    {
        $read = fn (int $n) => ['id' => "t{$n}_read", 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.read']];
        $write = fn (int $n, int $w) => ['id' => "t{$n}_write", 'name' => 'execute_operation', 'arguments' => ['operationId' => 'ledger.write', 'parameters' => ['body' => ['n' => $w]]]];
        $apps = fn (int $n) => ['id' => "t{$n}_apps", 'name' => 'list_applications', 'arguments' => []];
        $delegate = fn (int $n, int $h, string $task) => ['id' => "t{$n}_helper{$h}", 'name' => 'delegate_to_helper', 'arguments' => ['helper_agent_id' => $helperIds[$h], 'task' => $task]];

        // Every step but the helper batch also lists applications: a step
        // made only of operations that all succeed ends the task early.
        return [
            1 => [$read(1), $apps(1)],
            2 => [$write(2, 1), $apps(2)],
            3 => [$read(3), $apps(3)],
            4 => [$write(4, 2), $apps(4)],
            5 => [$write(5, 3), $read(5), $apps(5)],
            6 => [$delegate(6, 0, 'Part one.'), $delegate(6, 1, 'Part two.'), $delegate(6, 2, 'Part three.')],
            7 => [$read(7), $apps(7)],
            8 => [$write(8, 4), $apps(8)],
            9 => [$write(9, 5), $apps(9)],
            10 => [$read(10), $apps(10)],
            11 => [$write(11, 6), $apps(11)],
        ];
    }

    /** @return array<string, int> the effectful calls: call id => the write's number */
    private static function writeCalls(): array
    {
        return ['t2_write' => 1, 't4_write' => 2, 't5_write' => 3, 't8_write' => 4, 't9_write' => 5, 't11_write' => 6];
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /** The user, server, model assignment, root agent with three helpers, and the host's tables. */
    private function prepareEnvironment(): void
    {
        $this->app['config']->set('llm-client.run_trace.enabled', true);

        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        // The host application owns the users table; the package's migrations do not create it.
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
            'name' => 'Trial User',
            'email' => 'trial@example.com',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
        $this->userId = $user->id;

        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Trial Server',
            'provider_type' => 'openai',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test-token',
        ]);
        $this->serverId = $server->id;

        app(RoleAssignmentService::class)->set(ModelRole::Inference, $user->id, $server->id, 'gpt-4');

        // Defining an agent reads the host's API description; there is none here.
        $this->app->instance(\Dedoc\Scramble\Generator::class, new class extends \Dedoc\Scramble\Generator
        {
            public function __construct()
            {
            }

            public function __invoke(?\Dedoc\Scramble\GeneratorConfig $config = null): array
            {
                return ['paths' => []];
            }
        });

        $agents = app(AgentService::class);
        $root = $agents->create($user->id, "name: root\ninstructions: I am root.");
        $this->rootAgentId = $root->id;
        $this->rootAgentVersionId = $root->current_version_id;

        $this->helperIds = [];
        foreach (['a', 'b', 'c'] as $name) {
            $helper = $agents->create($user->id, "name: {$name}\ninstructions: I am {$name}.");
            app(AgentHelperService::class)->assign($user->id, $root->id, $helper->id);
            $this->helperIds[] = $helper->id;
        }

        file_put_contents($this->scratchDir().'/config.json', json_encode([
            'connection' => $this->getSpec()->toConnectionConfig(),
            'app_key' => $this->app['config']->get('app.key'),
            'plan' => self::plan($this->helperIds),
            'final_reply' => self::FINAL_REPLY,
            'window_seconds' => self::WINDOW_SECONDS,
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->scratchDir().'/worker.php', $this->workerScript());
    }

    private function makeConversation(): Conversation
    {
        return Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userId,
            'server_id' => $this->serverId,
            'model' => 'gpt-4',
            'title' => 'Trial task',
            'character' => 'Assistant',
            'agent_id' => $this->rootAgentId,
            'agent_version_id' => $this->rootAgentVersionId,
        ]);
    }

    // ---------------------------------------------------------------
    // Worker processes
    // ---------------------------------------------------------------

    /**
     * Run one worker process to its end.
     *
     * @return array{killed: bool, exit: int|null, ended_at: float, seconds: float, stdout: string, stderr: string}
     */
    private function worker(string $mode, string $conversationId, string $ledger, string $fault): array
    {
        $scratch = $this->scratchDir();
        $process = new Process(
            [PHP_BINARY, $scratch.'/worker.php', $mode, $conversationId],
            dirname(__DIR__, 2),
            [
                'CLARION_WORKER_ROOT' => dirname(__DIR__, 2),
                'CLARION_WORKER_CONFIG' => $scratch.'/config.json',
                'CLARION_WORKER_LEDGER' => $ledger,
                'LLM_CLIENT_FAULT_POINT' => $fault,
            ]
        );
        $process->setTimeout(120);
        $began = microtime(true);
        $process->start();

        // Poll rather than wait(): the moment the worker is seen dead is the
        // moment the kill is measured from.
        $endedAt = microtime(true);
        while ($process->isRunning()) {
            usleep(10_000);
        }
        $endedAt = microtime(true);

        return [
            'killed' => $process->hasBeenSignaled() && $process->getTermSignal() === 9,
            'exit' => $process->getExitCode(),
            'ended_at' => $endedAt,
            'seconds' => $endedAt - $began,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    /** One line for a failure message. */
    private function describe(string $mode, array $result): string
    {
        $tail = trim(substr($result['stderr'], -160));

        return $mode.' '.round($result['seconds'], 1).'s: '.($result['killed'] ? 'killed' : 'exit '.var_export($result['exit'], true))
            .($tail !== '' ? ', stderr "'.str_replace("\n", ' / ', $tail).'"' : '')
            .(!$result['killed'] && trim($result['stdout']) !== '' ? ', stdout "'.substr(trim($result['stdout']), 0, 120).'"' : '');
    }

    /**
     * The worker program: the package booted against the ephemeral database,
     * a scripted model, the file-backed effect ledger and the killing fault
     * injector. Modes: `start` (the request: run the task) and `resume` (one
     * sweep, then one queued job).
     */
    private function workerScript(): string
    {
        return <<<'PHP'
        <?php

        use ClarionApp\LlmClient\Contracts\FaultInjector;
        use ClarionApp\LlmClient\LlmClientServiceProvider;
        use ClarionApp\LlmClient\Models\Conversation;
        use ClarionApp\LlmClient\Services\AgentLoopService;
        use ClarionApp\LlmClient\Services\McpToolExecutor;
        use ClarionApp\LlmClient\Services\McpToolRegistry;
        use Dedoc\Scramble\Generator;
        use Dedoc\Scramble\GeneratorConfig;
        use Illuminate\Queue\Events\JobProcessing;
        use Illuminate\Support\Facades\Artisan;
        use Illuminate\Support\Facades\Event;
        use Illuminate\Support\Facades\Http;
        use Tests\Integration\Harness\CapturedPayload;
        use Tests\Integration\Harness\DeterministicEmbedder;
        use Tests\Integration\Harness\LaneRule;
        use Tests\Integration\Harness\OperationCatalogue;
        use Tests\Integration\Harness\RequestLane;
        use Tests\Integration\Harness\ResponseScript;
        use Tests\Integration\Harness\ScriptedTransport;
        use Tests\Support\EffectLedger;
        use Tests\RealDatabase\Support\KillingFaultInjector;

        $root = getenv('CLARION_WORKER_ROOT');
        require_once $root.'/vendor/autoload.php';

        $config = json_decode(file_get_contents(getenv('CLARION_WORKER_CONFIG')), true);
        $mode = $argv[1];
        $conversationId = $argv[2];

        if (!class_exists('App\Http\Controllers\Controller')) {
            eval('namespace App\Http\Controllers { class Controller { } }');
        }

        /** The package booted the way the real-database tests boot it, against the ephemeral database. */
        class TrialApplication extends Orchestra\Testbench\Foundation\Application
        {
            public static array $trial = [];

            protected function defineEnvironment($app)
            {
                $app->singleton('multichain', function () {
                    return new class {
                        public function __call($method, $arguments) { return null; }
                        public function publish($stream, $key, $value) { return 'stub-txid'; }
                        public function liststreams($stream) { throw new \Exception('not found'); }
                        public function create($type, $name, $private) { return null; }
                        public function subscribe($stream) { return null; }
                    };
                });
            }

            protected function getEnvironmentSetUp($app)
            {
                $cfg = $app['config'];
                $trial = static::$trial;

                $cfg->set('app.key', $trial['app_key']);
                $cfg->set('app.timezone', 'UTC');
                $cfg->set('database.default', 'mysql');
                $cfg->set('database.connections.mysql', $trial['connection']);
                $cfg->set('cache.default', 'array');
                $cfg->set('eloquent-multichain-bridge.disabled', true);
                $cfg->set('broadcasting.default', 'null');

                // Logged to stderr: a worker that quietly did nothing would
                // otherwise look like one that found nothing to do.
                $cfg->set('logging.default', 'trial_stderr');
                $cfg->set('logging.channels.trial_stderr', [
                    'driver' => 'monolog',
                    'handler' => Monolog\Handler\StreamHandler::class,
                    'with' => ['stream' => 'php://stderr', 'level' => Monolog\Level::Warning],
                ]);

                // Requests run the task and its helpers inline; the resume
                // sweep alone queues (on the database), see below.
                $cfg->set('queue.default', 'sync');
                $cfg->set('queue.connections.database', [
                    'driver' => 'database',
                    'connection' => 'mysql',
                    'table' => 'jobs',
                    'queue' => 'default',
                    'retry_after' => 86400,
                    'after_commit' => false,
                ]);

                $cfg->set('auth.defaults.guard', 'api');
                $cfg->set('auth.guards.api', ['driver' => 'token', 'provider' => 'users']);
                $cfg->set('auth.providers.users', ['driver' => 'eloquent', 'model' => \ClarionApp\Backend\Models\User::class]);

                $cfg->set('llm-client.memory.embedding.dimension', 8);
                $cfg->set('llm-client.run_trace.enabled', true);

                // The detection window, and every lease a worker takes, are
                // one second: a dead worker is detected a second after it dies.
                $cfg->set('llm-client.resume.detection_window_seconds', $trial['window_seconds']);
                $cfg->set('llm-client.resume.helper_lease_seconds', $trial['window_seconds']);
                $cfg->set('llm-client.resume.lease_grace_seconds', 0);
                $cfg->set('llm-client.resume.queue', 'resume-trial');
                $cfg->set('llm-client.providers.openai.timeout', 1);
                $cfg->set('llm-client.providers.anthropic.timeout', 1);
                $cfg->set('llm-client.providers.llama_cpp.timeout', 1);
                $cfg->set('llm-client.delegation.max_seconds', 3);
                $cfg->set('llm-client.delegation.max_iterations', 50);
            }
        }

        TrialApplication::$trial = [
            'app_key' => $config['app_key'],
            'connection' => $config['connection'],
            'window_seconds' => $config['window_seconds'],
        ];

        $app = TrialApplication::create(
            options: ['extra' => ['providers' => [LlmClientServiceProvider::class]]],
        );

        try {
            // The scripted model: what it answers is a function of the
            // conversation it is shown, never of a counter in this process, so
            // a resumed worker is served exactly as the first one was.
            $plan = $config['plan'];
            $finalReply = $config['final_reply'];
            $script = new ResponseScript();
            $script->addRule(new LaneRule(
                lane: RequestLane::AgentTurn,
                predicate: fn (CapturedPayload $payload) => true,
                respond: function (CapturedPayload $payload) use ($plan, $finalReply) {
                    foreach ($payload->messages as $message) {
                        $content = $message['content'] ?? null;
                        if (($message['role'] ?? null) === 'user' && is_string($content) && str_contains($content, "## Task\n")) {
                            // A helper's own conversation: it answers at once.
                            return (new ResponseScript())->finalAnswer(json_encode(['status' => 'success', 'summary' => 'Done.', 'output' => (object) [], 'undone' => '']))->usage(10, 10)->steps[0];
                        }
                    }
                    $steps = count(array_filter($payload->messages, fn ($m) => ($m['role'] ?? null) === 'assistant' && !empty($m['tool_calls'])));
                    $calls = $plan[$steps + 1] ?? null;

                    return $calls === null
                        ? (new ResponseScript())->finalAnswer($finalReply)->usage(10, 10)->steps[0]
                        : (new ResponseScript())->toolRequests($calls)->usage(10, 10)->steps[0];
                },
                label: 'trial-task',
            ));
            $transport = new ScriptedTransport($script, new DeterministicEmbedder(8));
            $app->bind('llm-client.http_handler', fn () => $transport->handlerStack());

            // The effectful operation writes one durable line, in a file
            // outside the database, every time it executes.
            $ledger = (new EffectLedger())->withFile(getenv('CLARION_WORKER_LEDGER'));
            $app->singleton(
                McpToolExecutor::class,
                fn () => new McpToolExecutor($app->make(McpToolRegistry::class), null, fn ($user) => 'trial-token-'.$user->id),
            );
            $document = [
                'openapi' => '3.0.0',
                'info' => ['title' => 'Ledger API', 'version' => '1.0.0'],
                'paths' => [
                    '/ledger/write' => ['post' => ['operationId' => 'ledger.write', 'summary' => 'Write to the ledger (effectful)', 'parameters' => [], 'responses' => ['200' => ['description' => 'Success']]]],
                    '/ledger/read' => ['get' => ['operationId' => 'ledger.read', 'summary' => 'Read from the ledger (effect-free)', 'parameters' => [], 'responses' => ['200' => ['description' => 'Success']]]],
                ],
            ];
            $app->instance(Generator::class, new class($document) extends Generator {
                public function __construct(private array $document) {}
                public function __invoke(?GeneratorConfig $config = null) { return $this->document; }
            });
            $catalogue = new OperationCatalogue();
            $catalogue->seed($document);
            $catalogue->fakeHostApi([
                '/ledger/write' => function ($request) use ($ledger) {
                    $n = (int) ($request->data()['n'] ?? 0);
                    $ledger->record('ledger.write', ['n' => $n]);
                    return Http::response(['ok' => true, 'operation' => 'ledger.write'], 200, ['Content-Type' => 'application/json']);
                },
                '/ledger/read' => fn () => Http::response(['ok' => true, 'operation' => 'ledger.read'], 200, ['Content-Type' => 'application/json']),
                // A helper's conversation starts untitled and asks for a title on the side.
                '/chat/completions' => fn () => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(['title' => 'Trial work'])]]]], 200, ['Content-Type' => 'application/json']),
            ]);

            // Every queued job starts in a fresh scope, as a worker's does.
            Event::listen(JobProcessing::class, function () use ($app) {
                $app->forgetScopedInstances();
            });

            // The fault injector is the only thing that differs from production.
            $app->instance(FaultInjector::class, new KillingFaultInjector(getenv('LLM_CLIENT_FAULT_POINT') ?: ''));

            if ($mode === 'start') {
                $conversation = Conversation::findOrFail($conversationId);
                $result = $app->make(AgentLoopService::class)->run($conversation, 'Run the trial task.');
                echo json_encode(['ran' => true, 'keys' => array_keys((array) $result)]);
            } else {
                // The sweep queues its resume job on the database; the job is
                // then taken by a queue worker, which runs the helpers it
                // delegates to inline.
                $app['config']->set('queue.default', 'database');
                Artisan::call('llm-client:resume-interrupted-runs');
                $sweep = Artisan::output();
                $app['config']->set('queue.default', 'sync');
                Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--queue' => 'resume-trial']);
                echo json_encode(['sweep' => trim($sweep), 'work' => trim(Artisan::output())]);
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
            exit(1);
        }
        PHP;
    }

    // ---------------------------------------------------------------
    // Scratch directory
    // ---------------------------------------------------------------

    private function scratchDir(): string
    {
        if ($this->scratch === null) {
            $this->scratch = sys_get_temp_dir().'/resume_fault_injection_'.uniqid();
            mkdir($this->scratch, 0700, true);
        }

        return $this->scratch;
    }

    private function removeScratch(): void
    {
        if ($this->scratch === null || !is_dir($this->scratch)) {
            $this->scratch = null;

            return;
        }

        foreach (glob($this->scratch.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->scratch);

        $this->scratch = null;
    }
}
