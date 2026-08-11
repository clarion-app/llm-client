<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\RubricJudge;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * research.md D7: every judging-failure mode converges on one boundary and
 * produces `unjudged`, never a thrown exception. Only role resolution,
 * BudgetGate, and output parsing/validation are exercised for real —
 * RoleResolver and BudgetGate both read/write real tables via the base
 * schema every Tests\TestCase declares. The only thing faked is the
 * provider itself (no real HTTP), bound into the real ProviderRegistry the
 * same way EmbeddingServiceTest/RoleAssignmentJudgeJourneyTest already do.
 */
class RubricJudgeTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('eval_judgments')->delete();
        DB::table('conversations')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('llm_servers')->delete();
        DB::table('cost_summaries')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    private function judge(): RubricJudge
    {
        return app(RubricJudge::class);
    }

    private function makeServer(string $name = 'Judge Server'): Server
    {
        return Server::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'server_url' => 'https://api.example.com',
            'provider_type' => ProviderType::OpenAI,
        ]);
    }

    private function assignJudgeRole(Server $server, string $model = 'gpt-4o-mini'): RoleAssignment
    {
        return RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => $model,
        ]);
    }

    private function judgeConversation(): Conversation
    {
        return Conversation::create([
            'user_id' => null,
            'title' => 'eval-judgment:test-'.Str::uuid(),
            'character' => 'eval-judge',
        ]);
    }

    /**
     * Registers a fixture provider whose chat() call is driven entirely by
     * the given callback — no real HTTP, matching every provider-fixture
     * precedent already established elsewhere in this package's tests.
     */
    private function registerProvider(callable $chatCallback): void
    {
        $provider = $this->createMock(LlmProvider::class);
        $provider->method('chat')->willReturnCallback($chatCallback);

        app(ProviderRegistry::class)->register(ProviderType::OpenAI, fn () => $provider);
    }

    private function registerThrowingProvider(\Throwable $exception): void
    {
        $provider = $this->createMock(LlmProvider::class);
        $provider->method('chat')->willThrowException($exception);

        app(ProviderRegistry::class)->register(ProviderType::OpenAI, fn () => $provider);
    }

    /** @return array<string, mixed> the chat()-shaped return value */
    private function chatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 20, 'total_tokens' => 60],
            'model' => 'gpt-4o-mini',
        ];
    }

    /**
     * SpendingCeilingService rejects a literal zero amount outright
     * ("amount must be greater than zero"), so a ceiling is "already
     * reached" only by comparison against real prior consumption, not by
     * a zero ceiling alone — set a small positive ceiling and seed a
     * cost_summaries row (the same table BudgetLedger::forInstallation()
     * sums) already at or above it.
     */
    private function reachStoppingCeiling(): void
    {
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::Installation,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            [
                'amount' => '0.01',
                'period_type' => 'month',
                'enforcement_mode' => 'stop',
            ],
        );

        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => \ClarionApp\LlmClient\Models\CostSummary::ENTITY_USER,
            'entity_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'period_date' => now()->toDateString(),
            'request_count' => 1,
            'priced_cost_total' => '1.0000000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => now(),
        ]);
    }

    private function callJudge(
        string $criteria = 'Must acknowledge frustration before offering a solution.',
        string $given = 'The customer is upset about a late delivery.',
        ?string $producedResponse = 'I understand this has been frustrating.',
    ): \ClarionApp\LlmClient\ValueObjects\RubricJudgmentResult {
        return $this->judge()->judge(
            $criteria,
            $given,
            $producedResponse,
            [],
            $this->judgeConversation(),
            'eval_rubric_judgment',
        );
    }

    // ---------------------------------------------------------------
    // Role unassigned / broken ⇒ unjudged, never a thrown exception
    // ---------------------------------------------------------------

    #[Test]
    public function unjudged_when_the_judge_role_is_unassigned(): void
    {
        // No RoleAssignment for 'judge' exists at all.
        $result = $this->callJudge();

        $this->assertSame('unjudged', $result->status);
        $this->assertNotEmpty($result->unjudgedReason);
        $this->assertNull($result->score);
        $this->assertNull($result->justification);
        $this->assertNull($result->model);
    }

    #[Test]
    public function unjudged_when_the_judge_role_is_broken(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);

        // Soft-deleting the server is RoleResolver::isBroken()'s own
        // "server deleted" branch.
        $server->delete();

        $result = $this->callJudge();

        $this->assertSame('unjudged', $result->status);
        $this->assertNotEmpty($result->unjudgedReason);
    }

    // ---------------------------------------------------------------
    // BudgetGate refusal ⇒ unjudged, never propagated
    // ---------------------------------------------------------------

    #[Test]
    public function unjudged_when_the_spending_ceiling_refuses_the_call(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode(['score' => 8, 'justification' => 'Fine.'])));

        $this->reachStoppingCeiling();

        $result = $this->callJudge();

        $this->assertSame('unjudged', $result->status);
        $this->assertNotEmpty($result->unjudgedReason);
        $this->assertNull($result->score);
    }

    // ---------------------------------------------------------------
    // Provider throws (network/timeout-shaped) ⇒ unjudged
    // ---------------------------------------------------------------

    #[Test]
    public function unjudged_when_the_provider_call_throws(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerThrowingProvider(new \RuntimeException('Connection timed out'));

        $result = $this->callJudge();

        $this->assertSame('unjudged', $result->status);
        $this->assertNotEmpty($result->unjudgedReason);
    }

    // ---------------------------------------------------------------
    // Malformed / out-of-range response ⇒ unjudged
    // ---------------------------------------------------------------

    #[Test]
    public function unjudged_when_the_response_has_no_parseable_json_at_all(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse('I refuse to answer in JSON, sorry.'));

        $result = $this->callJudge();

        $this->assertSame('unjudged', $result->status);
        $this->assertNotEmpty($result->unjudgedReason);
    }

    #[Test]
    public function unjudged_when_score_is_missing(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode(['justification' => 'Fine, but no score given.'])));

        $result = $this->callJudge();

        $this->assertSame('unjudged', $result->status);
        $this->assertNotEmpty($result->unjudgedReason);
    }

    #[Test]
    public function unjudged_when_score_is_not_an_integer(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode(['score' => 'eight', 'justification' => 'Fine.'])));

        $result = $this->callJudge();

        $this->assertSame('unjudged', $result->status);
        $this->assertNotEmpty($result->unjudgedReason);
    }

    #[Test]
    public function unjudged_when_score_is_below_one(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode(['score' => 0, 'justification' => 'Fine.'])));

        $result = $this->callJudge();

        $this->assertSame('unjudged', $result->status);
        $this->assertNotEmpty($result->unjudgedReason);
    }

    #[Test]
    public function unjudged_when_score_is_above_the_configured_max(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode([
            'score' => (int) config('llm-client.eval_judging.score_scale_max', 10) + 1,
            'justification' => 'Fine.',
        ])));

        $result = $this->callJudge();

        $this->assertSame('unjudged', $result->status);
        $this->assertNotEmpty($result->unjudgedReason);
    }

    #[Test]
    public function unjudged_when_justification_is_empty(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode(['score' => 7, 'justification' => ''])));

        $result = $this->callJudge();

        $this->assertSame('unjudged', $result->status);
        $this->assertNotEmpty($result->unjudgedReason);
    }

    #[Test]
    public function unjudged_when_justification_is_missing(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(json_encode(['score' => 7])));

        $result = $this->callJudge();

        $this->assertSame('unjudged', $result->status);
        $this->assertNotEmpty($result->unjudgedReason);
    }

    // ---------------------------------------------------------------
    // Happy path
    // ---------------------------------------------------------------

    #[Test]
    public function judged_on_a_well_formed_response(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server, 'gpt-4o-mini');
        $this->registerProvider(fn () => $this->chatResponse(json_encode([
            'score' => 8,
            'justification' => "Acknowledges the customer's frustration clearly before offering help.",
        ])));

        $conversation = $this->judgeConversation();

        $result = $this->judge()->judge(
            'Must acknowledge frustration before offering a solution.',
            'The customer is upset about a late delivery.',
            'I understand this has been frustrating, and I am sorry for the delay.',
            [],
            $conversation,
            'eval_rubric_judgment',
        );

        $this->assertSame('judged', $result->status);
        $this->assertSame(8, $result->score);
        $this->assertSame("Acknowledges the customer's frustration clearly before offering help.", $result->justification);
        $this->assertNull($result->unjudgedReason);
        $this->assertSame('gpt-4o-mini', $result->model);
        $this->assertSame($server->id, $result->serverId);
        $this->assertSame($conversation->id, $result->conversationId);
    }

    // ---------------------------------------------------------------
    // Fallback JSON extraction: don't trust response_format alone
    // ---------------------------------------------------------------

    #[Test]
    public function judged_when_the_json_object_is_wrapped_in_extra_prose(): void
    {
        $server = $this->makeServer();
        $this->assignJudgeRole($server);
        $this->registerProvider(fn () => $this->chatResponse(
            'Sure, here is my evaluation: '
            .json_encode(['score' => 6, 'justification' => 'Adequate, but somewhat generic.'])
            .' Hope that helps!'
        ));

        $result = $this->callJudge();

        $this->assertSame('judged', $result->status);
        $this->assertSame(6, $result->score);
        $this->assertSame('Adequate, but somewhat generic.', $result->justification);
    }
}
