<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\ConversationWorkCeilingService;
use ClarionApp\LlmClient\Services\ConversationWorkCounter;
use ClarionApp\LlmClient\Services\RateLimitCounter;
use ClarionApp\LlmClient\Services\RateLimitService;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkScope;
use ClarionApp\LlmClient\ValueObjects\RateLimitScope;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * The closed-set guard over RateLimitCounter::peek()/ConversationWorkCounter::peek()
 * call sites (contracts §3, research.md D9, quickstart.md mutation-testing
 * row 11), now that both consumers of the read-only primitive exist:
 * DegradationGate (US1's own conversation_work axis computation, T034) and
 * DegradationStatusController (US4, T063/T064).
 *
 * Two independent properties are asserted, both pure source-text checks
 * needing no database and no application container:
 *
 *  - peek( is never called from outside the allowed set of files. This is
 *    a subset check, not an equality check — unlike RateLimitCallSiteGuardTest's
 *    "the whole closed set must be occupied" discipline, this file is
 *    written and run BEFORE DegradationStatusController exists, so the
 *    allowed set legitimately has zero occupants for RateLimitCounter::peek()
 *    today. That is a real, if trivial, pass — not a bug in this test —
 *    and is expected to stay green once the controller lands and starts
 *    calling peek() itself.
 *  - Neither counter's own increment() body, nor either sibling gate's own
 *    evaluate()/admit() bodies, ever call peek() internally — the specific
 *    "reuse increment() for simplicity" mutation row 11 names.
 *
 * A third property — that a live status check can never itself mutate the
 * allowance it reports on — is NOT provable by source text alone (the
 * mutation named by row 11 replaces peek()'s own body with a call to
 * increment(), which no grep over call SITES would catch, only inspecting
 * the read afterward). That property is the second test below, driven
 * through the real GET /degradation/status HTTP endpoint.
 */
class DegradationPeekCallSiteGuardTest extends TestCase
{
    private const RATE_LIMIT_PEEK_NEEDLE = 'RateLimitCounter::class)->peek(';

    private const CONVERSATION_WORK_PEEK_NEEDLE = 'ConversationWorkCounter::class)->peek(';

    /** The only files ever permitted to call RateLimitCounter::peek(). */
    private const RATE_LIMIT_PEEK_ALLOWLIST = [
        'Controllers/DegradationStatusController.php',
        'Services/DegradationGate.php',
    ];

    /** The only files ever permitted to call ConversationWorkCounter::peek(). */
    private const CONVERSATION_WORK_PEEK_ALLOWLIST = [
        'Controllers/DegradationStatusController.php',
        'Services/DegradationGate.php',
    ];

    private string $srcDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->srcDir = dirname(__DIR__, 2).'/src';
    }

    protected function tearDown(): void
    {
        DB::table('rate_limits')->delete();
        DB::table('conversation_work_ceilings')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Closed-set guard (pure source text, no DB/container needed)
    // -----------------------------------------------------------------

    #[Test]
    public function rate_limit_counter_peek_is_never_called_outside_the_allowed_set(): void
    {
        $found = $this->filesContaining(self::RATE_LIMIT_PEEK_NEEDLE);

        $unexpected = array_values(array_diff($found, self::RATE_LIMIT_PEEK_ALLOWLIST));

        $this->assertSame(
            [],
            $unexpected,
            "A new call site reads RateLimitCounter::peek() outside the closed set:\n"
            .implode("\n", $unexpected)
            ."\nOnly DegradationStatusController may call it (research.md D9) — never RateLimitGate/RateLimitCounter's own internals."
        );
    }

    #[Test]
    public function conversation_work_counter_peek_is_never_called_outside_the_allowed_set(): void
    {
        $found = $this->filesContaining(self::CONVERSATION_WORK_PEEK_NEEDLE);

        $unexpected = array_values(array_diff($found, self::CONVERSATION_WORK_PEEK_ALLOWLIST));

        $this->assertSame(
            [],
            $unexpected,
            "A new call site reads ConversationWorkCounter::peek() outside the closed set:\n"
            .implode("\n", $unexpected)
            ."\nOnly DegradationGate and DegradationStatusController may call it (research.md D9) — never "
            .'ConversationWorkGate/ConversationWorkCounter\'s own internals.'
        );
    }

    #[Test]
    public function neither_counters_own_increment_body_ever_calls_peek(): void
    {
        $this->assertMethodBodyNeverContains(
            $this->srcDir.'/Services/RateLimitCounter.php',
            'increment',
            '->peek(',
            'RateLimitCounter::increment() must never call its own peek() — the two are independent primitives, '
            .'one mutating and one not (quickstart.md mutation-testing row 11).'
        );

        $this->assertMethodBodyNeverContains(
            $this->srcDir.'/Services/ConversationWorkCounter.php',
            'increment',
            '->peek(',
            'ConversationWorkCounter::increment() must never call its own peek().'
        );
    }

    #[Test]
    public function neither_sibling_gates_admit_or_evaluate_bodies_ever_call_peek(): void
    {
        foreach (['evaluate', 'admit'] as $method) {
            $this->assertMethodBodyNeverContains(
                $this->srcDir.'/Services/RateLimitGate.php',
                $method,
                '->peek(',
                "RateLimitGate::{$method}() must never call peek() — admission always mutates via increment(), "
                .'never reads the non-mutating primitive built for status checks alone.'
            );

            $this->assertMethodBodyNeverContains(
                $this->srcDir.'/Services/ConversationWorkGate.php',
                $method,
                '->peek(',
                "ConversationWorkGate::{$method}() must never call peek()."
            );
        }
    }

    // -----------------------------------------------------------------
    // Repeated-call non-mutation (real HTTP endpoint, real counters)
    // -----------------------------------------------------------------

    /**
     * quickstart.md mutation-testing row 11's second half: even if peek()'s
     * own source is untouched, a status check that (by mistake) routes
     * through increment() somewhere in DegradationStatusController's own
     * wiring would still silently consume the very allowance it is only
     * supposed to report on. Source-text guards above cannot see that —
     * this drives the real endpoint N times and asserts both counters'
     * mutating state is byte-identical before and after.
     */
    #[Test]
    public function repeated_status_calls_never_push_the_user_toward_their_own_rate_limit_or_conversation_work_ceiling(): void
    {
        $user = User::factory()->create();

        $server = Server::create([
            'name' => 'Primary Server',
            'server_url' => 'http://primary.local:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $conversation = Conversation::create([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'model' => 'big-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);

        app(RateLimitService::class)->upsert(RateLimitScope::UserDefault, RateLimit::INSTALLATION_SCOPE_ID, [
            'max_requests' => 100,
            'window_seconds' => 3600,
        ]);

        app(ConversationWorkCeilingService::class)->upsert(
            ConversationWorkScope::ConversationDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_work_units' => 100, 'window_seconds' => 3600],
        );

        $rateLimitBefore = app(RateLimitCounter::class)->peek($user->id, 3600);
        $conversationWorkBefore = app(ConversationWorkCounter::class)->peek($conversation->id, 3600);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->actingAs($user, 'api')->getJson(
                '/api/clarion-app/llm-client/degradation/status?conversation_id='.$conversation->id
            );

            $response->assertStatus(
                200,
                'GET /degradation/status must exist and succeed for this non-mutation property to be exercised at all'
            );
        }

        $rateLimitAfter = app(RateLimitCounter::class)->peek($user->id, 3600);
        $conversationWorkAfter = app(ConversationWorkCounter::class)->peek($conversation->id, 3600);

        $this->assertSame(
            $rateLimitBefore->count,
            $rateLimitAfter->count,
            'five repeated GET /degradation/status calls must never themselves increment the user\'s own rate-limit counter'
        );

        $this->assertSame(
            $conversationWorkBefore->count,
            $conversationWorkAfter->count,
            'five repeated GET /degradation/status calls must never themselves increment the conversation\'s own work counter'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return string[] relative paths, sorted, of every src/ file whose
     *                   contents contain $needle
     */
    private function filesContaining(string $needle): array
    {
        $found = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach (new RegexIterator($iterator, '/\.php$/') as $file) {
            $content = file_get_contents($file->getPathname());

            if ($content === false) {
                continue;
            }

            if (str_contains($content, $needle)) {
                $found[] = str_replace($this->srcDir.'/', '', $file->getPathname());
            }
        }

        sort($found);

        return $found;
    }

    private function assertMethodBodyNeverContains(
        string $path,
        string $methodName,
        string $needle,
        string $message
    ): void {
        if (!file_exists($path)) {
            // The owning class doesn't exist yet under this exact path in
            // some earlier phase's intermediate state; nothing to violate.
            $this->assertTrue(true);

            return;
        }

        $content = file_get_contents($path);
        $this->assertNotFalse($content);

        $body = $this->extractMethodBody($content, $methodName);

        if ($body === null) {
            // The method doesn't exist (yet) — nothing to violate either.
            $this->assertTrue(true);

            return;
        }

        $this->assertStringNotContainsString($needle, $body, $message);
    }

    /**
     * Extract the full body (including its own braces) of the first
     * method named $methodName found in $content, by brace-counting from
     * its opening `{` to the matching closing `}`. Returns null if no such
     * method is found.
     */
    private function extractMethodBody(string $content, string $methodName): ?string
    {
        if (!preg_match(
            '/function\s+'.preg_quote($methodName, '/').'\s*\([^)]*\)[^{]*\{/',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $openBracePos = $matches[0][1] + strlen($matches[0][0]) - 1;
        $depth = 0;
        $length = strlen($content);

        for ($i = $openBracePos; $i < $length; $i++) {
            if ($content[$i] === '{') {
                $depth++;
            } elseif ($content[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $openBracePos, $i - $openBracePos + 1);
                }
            }
        }

        return null;
    }
}
