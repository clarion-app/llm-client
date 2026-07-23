<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\ConversationScript;
use Tests\Integration\Harness\PlayedConversation;
use Tests\Integration\Harness\RequestLane;
use Tests\Integration\Harness\Responses;

/**
 * US3: An operation used early is still known late.
 *
 * Integration scenarios that verify an operation discovered and used at an
 * early turn is still known at a much later turn, with no rediscovery in
 * between — and that reduction of the history does not take the remembered
 * operation with it.
 *
 * These tests verify:
 * - Operation used early is known late without rediscovery (FR-009)
 * - Rediscovery between uses fails naming the turn (FR-009, FR-014)
 * - Operation still known across a context management boundary (FR-009, FR-012)
 * - Never-used operation discovers normally (acceptance scenario 4)
 *
 * Config deviation: none for T037/T038/T040 (default model tier is fine — the
 * conversations are short and never need to cross a budget). T039 declares
 * useSmallModelTier() inline, the same deviation US1/US2 use, so the seeded
 * over-budget history actually crosses the configured budget in a handful of
 * turns instead of ~300 (research R6).
 */
class OperationRecallJourneyTest extends MultiTurnTestCase
{
    /* --------------------------------------------------------------------------
     * T036: Fixture seams (shared setup for all tests)
     * --------------------------------------------------------------------------
     * Seed the host OpenAPI catalogue (research R7) and keep execute_operation's
     * HTTP call off the real network (SC-006). Everything inside the llm-client
     * boundary — OperationCache, buildKnownOperationsSection, handleExecuteOperation —
     * still runs for real; only the host application's API and its OpenAPI
     * generator (which cannot run under Testbench) are substituted.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Skip gracefully if any test earlier in this process has replaced
        // ApiManager with a Mockery alias/overload double: this story drives the
        // real OpenAPI catalogue seam (OperationCatalogue::seed()), which is
        // unavailable against a mock. No test does that today (the ones that
        // once did now seed $apiDocsCache directly), so this never fires — it is
        // a regression safety-net.
        if (! $this->operations()->isSeamAvailable()) {
            $this->markTestSkipped(
                'ApiManager has been replaced by a Mockery alias/overload mock '
                . 'by an earlier test in this process; the OpenAPI catalogue seam '
                . 'this story drives is unavailable. Runs cleanly under the '
                . 'canonical `phpunit tests/` order.'
            );
        }

        // McpToolExecutor resolves API tokens via Laravel Passport in
        // production. The test doubles that with a fixed per-user token
        // string that the scripted host API (fakeHostApi below) never
        // validates, avoiding a Passport dependency in this harness.
        $this->app->singleton(
            McpToolExecutor::class,
            fn () => new McpToolExecutor(
                $this->app->make(McpToolRegistry::class),
                null,
                fn ($user) => 'test-mcp-token-' . $user->id
            )
        );

        // T036: seed the host OpenAPI catalogue. The path is deliberately
        // '/contacts' (not '/api/contacts') — McpToolExecutor::executeHttpCall
        // prepends '/api' itself, so a seeded '/api' prefix would double up.
        $this->operations()->seed([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Test API', 'version' => '1.0.0'],
            'paths' => [
                '/contacts' => [
                    'get' => [
                        'operationId' => 'listContacts',
                        'summary' => 'List all contacts',
                        'parameters' => [],
                        'responses' => ['200' => ['description' => 'Success']],
                    ],
                ],
            ],
        ]);

        // T036: keep execute_operation's HTTP call off the network (SC-006).
        $this->operations()->fakeHostApi([
            '*' => ['contacts' => [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']]],
        ]);
    }

    /* --------------------------------------------------------------------------
     * T037: Operation used early is known late without rediscovery
     * --------------------------------------------------------------------------
     * Turn 1 uses (executes) listContacts directly — this is the "early use"
     * that populates OperationCache. Three ordinary turns follow with no
     * operation involvement. Turn 5 asks for contacts again; two AgentTurn-lane
     * rules react to whether the operation is known from the system prompt's
     * Known Operations section, mirroring what a real model would do either
     * way — 'known-operation-present' calls execute_operation directly,
     * 'known-operation-absent' is the rediscovery a forgetful model would
     * perform (never fires in this scenario; exercised by T038 instead).
     *
     * Acceptance scenario 1; FR-009
     */
    public function test_operation_used_early_is_known_late_without_rediscovery(): void
    {
        $this->scenario = 'operation_recall_no_rediscovery';

        $fixture = $this->fixture()->build();

        $script = ConversationScript::make()
            ->turn(
                'List my contacts please.',
                fn ($r) => $r->toolRequest('execute_operation', [
                    'operationId' => 'listContacts',
                    'parameters' => [],
                ]),
            )
            ->turn('Tell me a fun fact about migrations.', fn ($r) => $r->finalAnswer('Fact one.'))
            ->turn('Tell me another fun fact.', fn ($r) => $r->finalAnswer('Fact two.'))
            ->turn('And one more, please.', fn ($r) => $r->finalAnswer('Fact three.'))
            ->turn(
                'Can you list my contacts again?',
                // No ordered step queued — served reactively by the rules below.
                fn ($r) => null,
            )
            ->rule(
                RequestLane::AgentTurn,
                fn () => Responses::toolRequest('execute_operation', [
                    'operationId' => 'listContacts',
                    'parameters' => [],
                ]),
                predicate: fn (CapturedPayload $p, int $t) => $this->lastUserMessageIs($p, 'Can you list my contacts again?')
                    && $this->operationKnown($p),
                label: 'known-operation-present',
            )
            ->rule(
                RequestLane::AgentTurn,
                fn () => Responses::toolRequest('search_operations', ['query' => 'list contacts']),
                predicate: fn (CapturedPayload $p, int $t) => $this->lastUserMessageIs($p, 'Can you list my contacts again?')
                    && !$this->operationKnown($p),
                label: 'known-operation-absent',
            )
            ->requireRule('known-operation-present')
            ->maxTurns(5);

        $played = $this->driver()->play($script, $fixture->conversation);

        foreach ($played->turns as $record) {
            $this->assertSame(
                'completed',
                $record->status,
                "Turn {$record->index} should have completed (status: {$record->status})"
            );
        }

        // The operation is known at the late turn — populated when turn 1
        // executed it, read back from the system prompt's Known Operations
        // section on turn 5, without any tool having re-searched for it.
        $lateAgentTurnPayload = $this->firstPayloadForLane($played->turn(5), RequestLane::AgentTurn);
        $this->assertNotNull($lateAgentTurnPayload, 'Turn 5 should have an agent_turn payload.');
        $this->assertTrue(
            $this->operationKnown($lateAgentTurnPayload),
            'Turn 5 system prompt should list listContacts as a known operation (cached from turn 1).'
        );

        // The other half of FR-009: no rediscovery between the two uses.
        $this->assertNoRediscoveryBetweenUses($played);
    }

    /* --------------------------------------------------------------------------
     * T038: Rediscovery between uses fails naming the turn
     * --------------------------------------------------------------------------
     * Same shape as T037, except turn 3 forces the 'known-operation-absent'
     * rule to fire — simulating a real model rediscovering an operation it
     * should already have known — between the early use (turn 1) and the late
     * use (turn 5). Verification must both observe the rediscovery and, when
     * the "no rediscovery" claim is checked, fail naming the turn it occurred.
     *
     * Acceptance scenario 2; FR-009, FR-014
     */
    public function test_rediscovery_between_uses_fails_naming_the_turn(): void
    {
        $this->scenario = 'operation_rediscovery_failure';

        $fixture = $this->fixture()->build();

        $script = ConversationScript::make()
            ->turn(
                'List my contacts please.',
                fn ($r) => $r->toolRequest('execute_operation', [
                    'operationId' => 'listContacts',
                    'parameters' => [],
                ]),
            )
            ->turn('Tell me a fun fact.', fn ($r) => $r->finalAnswer('Fact one.'))
            ->turn(
                // Forced rediscovery turn: the rule below answers the first
                // (fresh user ask) request with a search_operations call; this
                // ordered step answers the turn's second request (after the
                // search result is in history), ending the turn normally.
                'Could you check my contacts once more?',
                fn ($r) => $r->finalAnswer('Here is what I found.'),
            )
            ->turn('Tell me another fun fact.', fn ($r) => $r->finalAnswer('Fact three.'))
            ->turn(
                'Can you list my contacts again?',
                fn ($r) => null,
            )
            ->rule(
                RequestLane::AgentTurn,
                fn () => Responses::toolRequest('search_operations', ['query' => 'list contacts']),
                predicate: fn (CapturedPayload $p, int $t) => $this->lastMessageRole($p) === 'user'
                    && $this->lastUserMessageIs($p, 'Could you check my contacts once more?'),
                label: 'known-operation-absent',
            )
            ->rule(
                RequestLane::AgentTurn,
                fn () => Responses::toolRequest('execute_operation', [
                    'operationId' => 'listContacts',
                    'parameters' => [],
                ]),
                predicate: fn (CapturedPayload $p, int $t) => $this->lastUserMessageIs($p, 'Can you list my contacts again?')
                    && $this->operationKnown($p),
                label: 'known-operation-present',
            )
            ->maxTurns(5);

        $played = $this->driver()->play($script, $fixture->conversation);

        foreach ($played->turns as $record) {
            $this->assertSame(
                'completed',
                $record->status,
                "Turn {$record->index} should have completed (status: {$record->status})"
            );
        }

        // Rediscovery is observed as a search_operations call in the captured
        // payloads (FR-009's clarification) — named to turn 3.
        $discoveries = $played->discoveryRequests();
        $this->assertCount(1, $discoveries, 'Exactly one rediscovery should have been forced.');
        $this->assertSame(3, $discoveries[0]['turn'], 'Rediscovery should be attributed to turn 3.');

        // The "no rediscovery" claim, evaluated against this broken
        // conversation, must fail naming the turn (FR-014).
        $exception = null;
        try {
            $this->assertNoRediscoveryBetweenUses($played);
        } catch (\RuntimeException $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception, 'Verification should fail when rediscovery occurs between the two uses.');
        $this->assertStringContainsString('turn 3', $exception->getMessage(), 'Failure should name the turn on which rediscovery occurred.');
    }

    /* --------------------------------------------------------------------------
     * T039: Operation still known across a context management boundary
     * --------------------------------------------------------------------------
     * Turn 1 uses the operation while sitting on a seeded over-budget history,
     * so context management reduces the history during that very turn — the
     * witness confirms this before anything else is asserted. Every turn after
     * that reuses the operation via the same reactive rule, proving the cache
     * (independent of message history) survives the reduction: reduction of
     * the history must not take the remembered operation with it.
     *
     * Declared deviation: a small model tier brings the budget within reach in
     * a handful of turns instead of ~300 (research R6) — the same deviation
     * US1/US2 use.
     *
     * Acceptance scenario 3; FR-009, FR-012
     */
    public function test_operation_still_known_across_a_context_management_boundary(): void
    {
        $this->scenario = 'operation_recall_across_context_boundary';

        $this->useSmallModelTier(context: 6000, responseReserve: 512);

        $fixture = $this->fixture()->build();
        $this->applyModelTier($fixture->conversation);
        $this->seedOverBudgetHistory($fixture->conversation);

        $script = ConversationScript::make()
            ->turn(
                'List my contacts please.',
                fn ($r) => $r->toolRequest('execute_operation', [
                    'operationId' => 'listContacts',
                    'parameters' => [],
                ]),
            )
            ->turn('Can you check my contacts again? (1)', fn ($r) => null)
            ->turn('Can you check my contacts again? (2)', fn ($r) => null)
            ->turn('Can you check my contacts again? (3)', fn ($r) => null)
            ->turn('Can you check my contacts again? (4)', fn ($r) => null)
            ->rule(
                RequestLane::AgentTurn,
                fn () => Responses::toolRequest('execute_operation', [
                    'operationId' => 'listContacts',
                    'parameters' => [],
                ]),
                predicate: fn (CapturedPayload $p, int $t) => $this->operationKnown($p),
                label: 'known-operation-present',
            )
            ->rule(
                RequestLane::Condensation,
                fn () => Responses::condensationSummary('The conversation covered a contact lookup.'),
                label: 'condensation_auto',
            )
            ->maxTurns(5);

        $played = $this->driver()->play($script, $fixture->conversation);

        // Witness first: without it, "still known across the boundary" could
        // pass vacuously because the boundary was never crossed (FR-012).
        $this->witness($fixture->conversation)->assertContextManagementActed();

        foreach ($played->turns as $record) {
            $this->assertSame(
                'completed',
                $record->status,
                "Turn {$record->index} should have completed (status: {$record->status})"
            );
        }

        // No rediscovery anywhere — the operation is used by rule alone from
        // turn 2 onward. If the rule had ever failed to match (because the
        // cache had been wiped by the reduction), the AgentTurn lane's
        // ordered queue for that turn is empty (no ordered step was pushed),
        // so serveFor() would have thrown a lane-exhaustion error and this
        // test would already have failed loudly before reaching this line.
        $this->assertNoRediscoveryBetweenUses($played);

        // Reduction genuinely happened somewhere in this conversation (the
        // witness above already proved it non-vacuously) — confirm it is
        // visible on at least one played turn too.
        $this->assertNotNull(
            $played->firstReducedTurn(),
            'At least one turn should have been marked as reduced.'
        );
    }

    /* --------------------------------------------------------------------------
     * T040: Never-used operation discovers normally
     * --------------------------------------------------------------------------
     * An operation never used earlier in the conversation triggers discovery
     * normally — this is not a failure, it is the expected first-use path.
     *
     * Acceptance scenario 4
     */
    public function test_never_used_operation_discovers_normally(): void
    {
        $this->scenario = 'operation_normal_discovery';

        $fixture = $this->fixture()->build();

        $script = ConversationScript::make()
            ->turn(
                'Can you find an operation to list my contacts?',
                fn ($r) => $r
                    ->toolRequest('search_operations', ['query' => 'list contacts'])
                    ->finalAnswer('I can look that up for you.'),
            )
            ->maxTurns(1);

        $played = $this->driver()->play($script, $fixture->conversation);

        $this->assertSame(
            'completed',
            $played->turn(1)->status,
            'The discovery turn should have completed.'
        );

        $discoveries = $played->discoveryRequests();
        $this->assertCount(
            1,
            $discoveries,
            'A never-used operation should trigger discovery normally — not be treated as a failure.'
        );
        $this->assertSame(1, $discoveries[0]['turn'], 'Discovery should be attributed to turn 1.');
    }

    /* --------------------------------------------------------------------------
     * SC-002 gap closure (Phase 8 T061): OperationCache::get() must be load-bearing
     * --------------------------------------------------------------------------
     * T037's "known late" assertion reads the system prompt's Known Operations
     * section, which is built by AgentLoopService::buildKnownOperationsSection()
     * from OperationCache::getEntries() — a method entirely independent of
     * OperationCache::get(). And handleExecuteOperation()'s own get() cache-miss
     * falls back gracefully to ApiManager::getOperationDetails(), which the fixture
     * keeps seeded (research R7) for the whole test — so a mutated get() (forced to
     * return null) never changes T037's outcome: the fallback always has the data
     * anyway.
     *
     * Simply emptying the host catalogue between the two uses does not close this
     * gap either — it was tried and rejected here. ApiCallValidator::validate()
     * makes its *own*, independent ApiManager::getOperationDetails() call to check
     * the operationId still exists, regardless of what handleExecuteOperation()
     * resolved from OperationCache::get() or from its own ApiManager fallback. An
     * empty catalogue fails that independent check unconditionally, so the second
     * use fails identically whether or not get() ever ran — it does not isolate
     * get() at all, it just breaks the turn outright (confirmed empirically: that
     * version of this test failed even against the real, unmutated get()).
     *
     * The seam that actually isolates get() is a *changed*, not emptied, catalogue.
     * Between the two uses, listContacts is re-seeded at a different path and
     * method (still present, so ApiCallValidator's existence check keeps passing
     * unconditionally either way — it does not compare path or method). turn 1's
     * use cached the *original* {GET, /contacts} via OperationCache::put(); a real,
     * unmodified OperationCache — untouched by the re-seed, which only touches
     * ApiManager's static catalogue via reflection (OperationCatalogue::seed()).
     * turn 3's execute_operation call is then made to answer a factual question
     * only the *source of {method, path}* can answer: with get() intact, it
     * resolves the ORIGINAL {GET, /contacts} from cache and the host fake returns
     * the original contacts payload; with get() forced to return null (the T061
     * mutation), handleExecuteOperation() falls through to a fresh
     * ApiManager::getOperationDetails() call, resolves the NEW {POST, /directory},
     * and the host fake returns a distinguishable "stale-catalogue" payload
     * instead. The assertion below checks the tool result content equals the
     * *original* payload — true only when the cache, not the catalogue, supplied
     * the operation details.
     */
    public function test_operation_execution_uses_the_cached_endpoint_not_a_freshly_resolved_one(): void
    {
        $this->scenario = 'operation_recall_cache_is_the_source_of_truth';

        $fixture = $this->fixture()->build();

        // Both possible endpoints' responses are distinguishable — '/contacts'
        // (the original, cached endpoint) and '/directory' (what a fresh
        // ApiManager lookup would resolve to after the mid-conversation
        // re-seed below) never share a substring, so OperationCatalogue's
        // pattern matching can't confuse the two.
        $this->operations()->fakeHostApi([
            '/directory' => ['contacts' => [['id' => 99, 'name' => 'STALE-CATALOGUE-LOOKUP']]],
            '*' => ['contacts' => [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']]],
        ]);

        $script = ConversationScript::make()
            ->turn(
                'List my contacts please.',
                fn ($r) => $r->toolRequest('execute_operation', [
                    'operationId' => 'listContacts',
                    'parameters' => [],
                ]),
            )
            ->turn(
                // Between the two uses, the host catalogue is re-seeded:
                // listContacts still exists (so ApiCallValidator's existence
                // check keeps passing either way) but now resolves to a
                // different method and path. turn 1's cache entry — the
                // original {GET, /contacts} — is untouched by this.
                'Tell me a fun fact.',
                function ($r) {
                    $this->operations()->seed([
                        'openapi' => '3.0.0',
                        'info' => ['title' => 'Test API', 'version' => '1.0.0'],
                        'paths' => [
                            '/directory' => [
                                'post' => [
                                    'operationId' => 'listContacts',
                                    'summary' => 'List all contacts (relocated)',
                                    'parameters' => [],
                                    'responses' => ['200' => ['description' => 'Success']],
                                ],
                            ],
                        ],
                    ]);
                    $r->finalAnswer('Fact one.');
                },
            )
            ->turn(
                'Can you list my contacts again?',
                fn ($r) => $r->toolRequest('execute_operation', [
                    'operationId' => 'listContacts',
                    'parameters' => [],
                ]),
            )
            ->maxTurns(3);

        $played = $this->driver()->play($script, $fixture->conversation);

        foreach ($played->turns as $record) {
            $this->assertSame(
                'completed',
                $record->status,
                "Turn {$record->index} should have completed (status: {$record->status})"
            );
        }

        $lastMessage = Message::where('conversation_id', $fixture->conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('sequence_number')
            ->first();
        $toolResults = $lastMessage->tool_data['tool_results'] ?? [];
        $this->assertNotEmpty($toolResults, 'Turn 3 should have recorded a tool result.');

        $resultContent = json_decode($toolResults[0]['content'] ?? '{}', true);
        $this->assertSame(
            ['contacts' => [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']]],
            $resultContent,
            "Turn 3's execute_operation should have hit the endpoint OperationCache::get() " .
            'cached from turn 1 (GET /contacts), not a freshly resolved one from the ' .
            'catalogue as it stands now (POST /directory) — a fresh lookup would return ' .
            "the 'STALE-CATALOGUE-LOOKUP' payload instead, which is what happens when " .
            'get() is forced to miss.'
        );
    }

    /* --------------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------------- */

    /**
     * Whether the payload's system prompt lists listContacts as a known
     * operation. Uses extractSystemPrompt() (053) rather than
     * CapturedPayload::systemContains(), because this fixture's provider is
     * OpenAI-shaped — the system prompt travels as a message with role
     * 'system' in the messages array, not in the dedicated $system field.
     */
    protected function operationKnown(CapturedPayload $payload, string $needle = '**listContacts**'): bool
    {
        return str_contains($this->extractSystemPrompt($payload), $needle);
    }

    /**
     * The role of the last message in the payload (the most recent thing the
     * boundary would see) — 'user' means nothing has happened yet this turn,
     * 'tool' means a tool result was just appended.
     */
    protected function lastMessageRole(CapturedPayload $payload): ?string
    {
        if (empty($payload->messages)) {
            return null;
        }
        $last = end($payload->messages);
        return $last['role'] ?? null;
    }

    /**
     * Whether the payload's last message is a fresh user ask matching $text.
     *
     * Scoped to the *last* message deliberately: the same phrase can linger
     * earlier in accumulated history from a previous turn, and a rule keyed
     * on "contains this text anywhere" would misfire on later turns whose
     * history still includes it. Only the most recent ask should route.
     */
    protected function lastUserMessageIs(CapturedPayload $payload, string $text): bool
    {
        if (empty($payload->messages)) {
            return false;
        }
        $last = end($payload->messages);
        return ($last['role'] ?? null) === 'user' && str_contains((string) ($last['content'] ?? ''), $text);
    }

    /**
     * First payload of a given lane within a turn record, or null.
     */
    protected function firstPayloadForLane(\Tests\Integration\Harness\TurnRecord $record, RequestLane $lane): ?CapturedPayload
    {
        foreach ($record->payloads as $payload) {
            if (RequestLane::classify($payload) === $lane) {
                return $payload;
            }
        }
        return null;
    }

    /**
     * Assert no rediscovery occurred anywhere in the played conversation.
     *
     * Fails naming the turn on which rediscovery occurred (FR-009, FR-014) —
     * distinct from a plain assertEmpty(), which would report the mismatch
     * without locating the defect.
     */
    protected function assertNoRediscoveryBetweenUses(PlayedConversation $played): void
    {
        $discoveries = $played->discoveryRequests();
        if (!empty($discoveries)) {
            $first = $discoveries[0];
            throw new \RuntimeException(sprintf(
                'Rediscovery occurred at turn %d (query: "%s") — the operation should still ' .
                'have been known from an earlier use, without a new search_operations call.',
                $first['turn'],
                $first['query']
            ));
        }
    }

    /**
     * Seed over-budget history for a conversation (matches the pattern used
     * by LongConversationJourneyTest / RetainedInstructionJourneyTest).
     *
     * With small_test_tier (context=6000, response_reserve=512), the history
     * budget is a few thousand tokens; 100 messages of ~54 tokens each is
     * well over it.
     */
    protected function seedOverBudgetHistory(Conversation $conversation): int
    {
        $count = 100;

        for ($i = 0; $i < $count; $i++) {
            \ClarionApp\LlmClient\Models\Message::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'conversation_id' => $conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => str_repeat('word ', 50) . "(message {$i})",
                'sequence_number' => $i,
            ]);
        }

        return $count;
    }
}
