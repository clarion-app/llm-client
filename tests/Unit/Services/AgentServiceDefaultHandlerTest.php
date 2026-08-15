<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 102-router-pattern, Phase 6 (US4, T042).
 *
 * data-model.md §1, research.md D5, contracts/routing-mechanism.md's own
 * "no new authorization, no new controller" framing — AgentService::
 * setDefaultHandler()/clearDefaultHandler() mirror activate()/deactivate()'s
 * exact shape (AgentServiceTest.php's own activate()/deactivate() coverage
 * is this file's direct structural precedent, read in full before writing
 * these cases): a clean, no-write no-op when the flag is already at the
 * target value, and setDefaultHandler() additionally clears any other
 * `true` row for the same user_id inside a DB::transaction() before setting
 * the new one, so "at most one true row per user_id" never has a window
 * where it is violated.
 *
 * Written before AgentService::setDefaultHandler()/clearDefaultHandler()
 * exist — every test in this file is expected to FAIL with a fatal "Call
 * to undefined method AgentService::setDefaultHandler()" (or
 * ::clearDefaultHandler()) error until Phase 6's own implementation task
 * (T046) adds them.
 */
class AgentServiceDefaultHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers (AgentServiceTest.php's own precedent, verbatim)
    // ---------------------------------------------------------------

    private function service(): AgentService
    {
        return new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader());
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function seedOperationCatalog(array $operations = []): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
            ];
        }
        $doc = ['paths' => $paths];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // ---------------------------------------------------------------
    // setDefaultHandler() — an agent with no prior default
    // ---------------------------------------------------------------

    #[Test]
    public function set_default_handler_on_an_agent_with_no_prior_default_sets_is_default_handler_true(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agent = $this->service()->create($user->id, "name: agent-a\ninstructions: I am agent A.");
        $this->assertFalse((bool) $agent->fresh()->is_default_handler, 'fixture sanity: a freshly created agent defaults to no default-handler flag');

        $result = $this->service()->setDefaultHandler($agent);

        $this->assertTrue((bool) $result->is_default_handler, 'setDefaultHandler() must set is_default_handler to true');
        $this->assertTrue(
            (bool) DB::table('agents')->where('id', $agent->id)->value('is_default_handler'),
            'the flag must actually be persisted, not merely present on the in-memory model',
        );
    }

    // ---------------------------------------------------------------
    // setDefaultHandler() — atomic clear-then-set: never two true rows
    // ---------------------------------------------------------------

    #[Test]
    public function calling_set_default_handler_on_a_different_agent_atomically_clears_the_first_agents_flag(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agentA = $this->service()->create($user->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = $this->service()->create($user->id, "name: agent-b\ninstructions: I am agent B.");

        $this->service()->setDefaultHandler($agentA);
        $this->assertTrue(
            (bool) DB::table('agents')->where('id', $agentA->id)->value('is_default_handler'),
            'fixture sanity: agent A must actually be the default handler before the second call',
        );

        $this->service()->setDefaultHandler($agentB->fresh());

        // Direct DB reads, not just the returned model — proving the
        // invariant holds in the persisted state, not only in whatever
        // object happens to be held in memory.
        $this->assertFalse(
            (bool) DB::table('agents')->where('id', $agentA->id)->value('is_default_handler'),
            "designating a NEW default handler must atomically clear the PREVIOUS agent's own flag",
        );
        $this->assertTrue(
            (bool) DB::table('agents')->where('id', $agentB->id)->value('is_default_handler'),
            'the newly designated agent must now carry the flag',
        );

        $trueCount = DB::table('agents')
            ->where('user_id', $user->id)
            ->where('is_default_handler', true)
            ->count();
        $this->assertSame(1, $trueCount, 'never two true rows for one owner, at any point in time');
    }

    // ---------------------------------------------------------------
    // setDefaultHandler() — already-true is a clean no-op (mirrors
    // activate()'s own FR-014-style precedent)
    // ---------------------------------------------------------------

    #[Test]
    public function calling_set_default_handler_on_an_already_true_agent_is_a_clean_no_op_no_write(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agent = $this->service()->create($user->id, "name: agent-a\ninstructions: I am agent A.");
        $agent = $this->service()->setDefaultHandler($agent);
        $this->assertTrue((bool) $agent->is_default_handler, 'fixture sanity: the agent must actually be the default handler first');
        $updatedAtBefore = $agent->fresh()->updated_at;

        // updated_at alone is not a reliable no-write signal (AgentServiceTest's
        // own established rationale — Laravel's timestamps() columns carry
        // only second-level precision) — a query-log assertion closes that
        // gap by proving no UPDATE statement was issued at all.
        DB::enableQueryLog();
        $again = $this->service()->setDefaultHandler($agent);
        $updateQueries = array_filter(
            DB::getQueryLog(),
            fn (array $q) => str_starts_with(strtolower($q['query']), 'update')
        );
        DB::disableQueryLog();

        $this->assertTrue((bool) $again->is_default_handler);
        $this->assertCount(
            0,
            $updateQueries,
            'calling setDefaultHandler() on an already-true agent must perform no write — no UPDATE query may be issued',
        );
        $this->assertEquals(
            $updatedAtBefore,
            $again->fresh()->updated_at,
            'calling setDefaultHandler() on an already-true agent must perform no write — updated_at must be byte-identical',
        );
    }

    // ---------------------------------------------------------------
    // clearDefaultHandler() — a true agent flips to false
    // ---------------------------------------------------------------

    #[Test]
    public function clear_default_handler_on_a_true_agent_sets_is_default_handler_false(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agent = $this->service()->create($user->id, "name: agent-a\ninstructions: I am agent A.");
        $agent = $this->service()->setDefaultHandler($agent);
        $this->assertTrue((bool) $agent->is_default_handler, 'fixture sanity: the agent must actually be the default handler first');

        $cleared = $this->service()->clearDefaultHandler($agent);

        $this->assertFalse((bool) $cleared->is_default_handler, 'clearDefaultHandler() must flip is_default_handler to false');
        $this->assertFalse(
            (bool) DB::table('agents')->where('id', $agent->id)->value('is_default_handler'),
            'the cleared flag must actually be persisted',
        );
    }

    // ---------------------------------------------------------------
    // clearDefaultHandler() — already-false is a clean no-op
    // ---------------------------------------------------------------

    #[Test]
    public function clear_default_handler_on_an_already_false_agent_is_a_clean_no_op_no_write(): void
    {
        $this->seedOperationCatalog();
        $user = $this->user();
        $agent = $this->service()->create($user->id, "name: agent-a\ninstructions: I am agent A.");
        $this->assertFalse((bool) $agent->fresh()->is_default_handler, 'fixture sanity: a freshly created agent is never the default handler');
        $updatedAtBefore = $agent->fresh()->updated_at;

        DB::enableQueryLog();
        $again = $this->service()->clearDefaultHandler($agent);
        $updateQueries = array_filter(
            DB::getQueryLog(),
            fn (array $q) => str_starts_with(strtolower($q['query']), 'update')
        );
        DB::disableQueryLog();

        $this->assertFalse((bool) $again->is_default_handler);
        $this->assertCount(
            0,
            $updateQueries,
            'calling clearDefaultHandler() on an already-false agent must perform no write — no UPDATE query may be issued',
        );
        $this->assertEquals(
            $updatedAtBefore,
            $again->fresh()->updated_at,
            'calling clearDefaultHandler() on an already-false agent must perform no write — updated_at must be byte-identical',
        );
    }

    // ---------------------------------------------------------------
    // Per-user isolation — setting a default for one user never touches
    // another user's own default flag
    // ---------------------------------------------------------------

    #[Test]
    public function setting_a_default_for_one_user_never_affects_another_users_own_default_flag(): void
    {
        $this->seedOperationCatalog();
        $userA = $this->user();
        $userB = $this->user();
        $agentA = $this->service()->create($userA->id, "name: agent-a\ninstructions: I am agent A.");
        $agentB = $this->service()->create($userB->id, "name: agent-b\ninstructions: I am agent B.");

        $this->service()->setDefaultHandler($agentB);
        $this->assertTrue(
            (bool) DB::table('agents')->where('id', $agentB->id)->value('is_default_handler'),
            "fixture sanity: user B's own agent must actually be their default handler first",
        );

        $this->service()->setDefaultHandler($agentA);

        $this->assertTrue(
            (bool) DB::table('agents')->where('id', $agentA->id)->value('is_default_handler'),
            "user A's own designation must succeed",
        );
        $this->assertTrue(
            (bool) DB::table('agents')->where('id', $agentB->id)->value('is_default_handler'),
            "user A designating their own default handler must never clear user B's completely separate default flag — the clear-then-set transaction is scoped to the acted-on agent's own user_id",
        );
    }
}
