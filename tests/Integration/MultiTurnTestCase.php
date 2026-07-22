<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Conversation;
use Tests\Integration\Harness\ConversationDriver;
use Tests\Integration\Harness\ConversationScript;
use Tests\Integration\Harness\BoundaryWitness;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\Integration\Harness\ResponseScript;
use Tests\Integration\Harness\ScriptedTransport;
use Tests\Integration\Harness\ScriptedStream;
use Tests\Integration\Harness\SessionArtifacts;
use Tests\Integration\Harness\TurnPlan;

/**
 * T021: MultiTurnTestCase — base class for multi-turn verification scenarios.
 *
 * Extends AssembledSystemTestCase and adds:
 * - queue.default === 'sync' assertion in setUp (so deferred work runs inline)
 * - driver() method that returns a ConversationDriver
 * - witness() method that returns a BoundaryWitness
 * - operations() method that returns an OperationCatalogue
 * - useSmallModelTier() for declared config deviations
 *
 * Usage:
 * ```php
 * class MyMultiTurnTest extends MultiTurnTestCase
 * {
 *     public function testSomething(): void
 *     {
 *         $this->useSmallModelTier(context: 6000, responseReserve: 512);
 *         $fixture = $this->fixture()->build();
 *
 *         $script = ConversationScript::make()
 *             ->filler(fn (int $n) => "Step {$n}")
 *             ->rule(RequestLane::Condensation, Responses::condensationSummary())
 *             ->untilContextManagementActedAtLeast(1)
 *             ->maxTurns(40);
 *
 *         $played = $this->driver()->play($script, $fixture->conversation);
 *         $this->witness($fixture->conversation)->assertContextManagementActed();
 *     }
 * }
 * ```
 */
abstract class MultiTurnTestCase extends AssembledSystemTestCase
{
    protected ?ConversationDriver $driver = null;
    protected ?OperationCatalogue $operations = null;
    protected bool $smallModelTierRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Assert queue.default === 'sync' so deferred work runs inline
        if (config('queue.default') !== 'sync') {
            $this->fail(
                'Multi-turn tests require queue.default === "sync". ' .
                "Current: " . config('queue.default')
            );
        }

        $this->operations = new OperationCatalogue();
    }

    protected function tearDown(): void
    {
        // Reset operations catalogue unconditionally (D10)
        if ($this->operations) {
            $this->operations->reset();
        }

        // Clear any frozen clock unconditionally (D10). Phase 7's idle-sweep
        // scenario is the first to call Carbon::setTestNow() in this suite;
        // without this, a frozen clock would leak into whichever test runs
        // next in the same process.
        \Carbon\Carbon::setTestNow(null);

        // Reset driver so each test gets a fresh instance
        $this->driver = null;
        $this->smallModelTierRegistered = false;

        parent::tearDown();
    }

    /**
     * Register a small model tier for declared config deviations (research R6).
     *
     * Must be called BEFORE the first playTurn() — the budgeter reads config
     * at construction and the driver resolves the agent lazily for this reason.
     *
     * @param int $context Context window size in tokens.
     * @param int $responseReserve Response reserve in tokens.
     */
    protected function useSmallModelTier(int $context = 6000, int $responseReserve = 512): void
    {
        $tierName = 'small_test_tier';
        $models = config('llm-client.context_window.models', []);
        $models[$tierName] = [
            'context' => $context,
            'response_reserve' => $responseReserve,
        ];
        config(['llm-client.context_window.models' => $models]);

        // Point the fixture's conversation at this tier.
        // The fixture may not be built yet, so we record the tier name
        // and apply it when the driver resolves.
        $this->smallModelTierRegistered = true;
    }

    /**
     * Apply the small model tier to a conversation if registered.
     */
    protected function applyModelTier(Conversation $conversation): void
    {
        if ($this->smallModelTierRegistered) {
            $conversation->update(['model' => 'small_test_tier']);
            $conversation->refresh();
        }
    }

    /**
     * Create (or reuse) a ConversationDriver wired to the harness.
     *
     * The driver is created lazily so config deviations (useSmallModelTier,
     * condensation toggles) are in place before any service reads them (D6).
     *
     * @return ConversationDriver
     */
    protected function driver(): ConversationDriver
    {
        return $this->driver ??= new ConversationDriver(
            test: $this,
            script: $this->script,
            transport: $this->transport,
            stream: $this->stream,
        );
    }

    /**
     * Get the BoundaryWitness for a conversation.
     *
     * @param Conversation|string $conversation The conversation model or ID.
     * @return BoundaryWitness
     */
    protected function witness(Conversation|string $conversation): BoundaryWitness
    {
        $convId = $conversation instanceof Conversation
            ? $conversation->id
            : $conversation;

        return new BoundaryWitness($convId);
    }

    /**
     * Get the SessionArtifacts for a conversation — the end-state artifacts
     * for the turn/conversation boundary distinction (Phase 7, B1-B6).
     *
     * @param Conversation|string $conversation The conversation model or ID.
     * @return SessionArtifacts
     */
    protected function sessionArtifacts(Conversation|string $conversation): SessionArtifacts
    {
        $convId = $conversation instanceof Conversation
            ? $conversation->id
            : $conversation;

        return new SessionArtifacts($convId);
    }

    /**
     * Get the OperationCatalogue for seeding/resetting operations.
     *
     * @return OperationCatalogue
     */
    protected function operations(): OperationCatalogue
    {
        return $this->operations ??= new OperationCatalogue();
    }

    /**
     * Get the application container (for the driver).
     *
     * @return \Illuminate\Contracts\Container\Container
     */
    public function getApp(): \Illuminate\Contracts\Container\Container
    {
        return $this->app;
    }

    /**
     * Get the scenario name (for the driver).
     */
    public function getScenario(): string
    {
        return $this->scenario;
    }

    /**
     * Get captured chat payloads (for the driver).
     *
     * @return array
     */
    public function getCapturedChatPayloads(): array
    {
        return $this->capturedChatPayloads();
    }
}
