<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\RubricJudgmentPromptBuilder;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use ClarionApp\LlmClient\Services\ToolResultCondenser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature 111 — US1 (P1): a cited source must survive condensation, and the
 * consulted-source manifest must be derivable from the run trace.
 *
 * (a) The page/text tool result is a source envelope whose content is wrapped
 *     in the untrusted-response delimiters.
 * (b) When the content exceeds the condenser threshold, the source URL survives
 *     into the preserved-values block (extended extractPreservedValues()).
 * (c) RunTraceQuery::consultedSourcesForRun() returns exactly the URLs fetched,
 *     distinct, in started_at order, and ownership-checked.
 */
class CitationSurvivalCondensationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();
        Mockery::close();
        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('llm-client.tool_result_condensation', [
            'enabled' => true,
            'threshold_tokens' => 2000,
            'max_condensed_tokens' => 500,
            'summarization_timeout_seconds' => 5,
            'cache_ttl_minutes' => 240,
        ]);
    }

    // --- (a) The page/text tool result is a source envelope. ---

    #[Test]
    public function a_page_text_result_is_a_source_envelope_with_untrusted_wrapped_content(): void
    {
        $url = 'https://example.com/article';
        $body = 'The quick brown fox jumps over the lazy dog. '.str_repeat('A sentence of body text. ', 20);

        $envelope = AgentLoopService::buildPageTextEnvelope($url, 'Example Article', $body, 'conv-1');

        $this->assertSame($url, $envelope['source']['url']);
        $this->assertSame('Example Article', $envelope['source']['title']);
        $this->assertStringContainsString('--- BEGIN RESPONSE UNDER EVALUATION ---', $envelope['content']);
        $this->assertStringContainsString('--- END RESPONSE UNDER EVALUATION ---', $envelope['content']);
        $this->assertStringContainsString($body, $envelope['content']);
        // Under threshold: not condensed, so no reference id.
        $this->assertNull($envelope['reference_id']);
    }

    // --- (b) The source URL survives condensation of over-threshold content. ---

    #[Test]
    public function the_source_url_survives_condensation_of_over_threshold_content(): void
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => 'A concise summary of the page.']]],
        ]);

        $condenser = new ToolResultCondenser(null, $provider, null, [
            'enabled' => true,
            'threshold_tokens' => 100, // low so our content is over the threshold
            'max_condensed_tokens' => 500,
        ]);

        $url = 'https://example.com/article';
        $content = 'See '.$url.' for details. '.str_repeat('lorem ipsum dolor sit amet ', 100);

        $result = $condenser->condense('conv-1', 'execute_operation', $content);

        $this->assertTrue($result['condensed']);
        // The URL must survive into the preserved-values block.
        $this->assertStringContainsString($url, $result['content']);
    }

    // --- (c) The derived manifest lists exactly the URLs fetched. ---

    #[Test]
    public function the_derived_manifest_lists_exactly_the_fetched_urls_distinct_and_ordered(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $runId = (string) Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => $user->id,
            'conversation_id' => null,
            'started_at' => '2026-01-01 09:59:00.000000',
        ]);

        $stepId = (string) Str::uuid();
        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => $runId,
            'position' => 1,
            'started_at' => '2026-01-01 10:00:00.000000',
        ]);

        // Two page/text actions for the same URL (dedupe) plus a second URL,
        // in started_at order.
        $this->insertPageTextAction($runId, $stepId, 'https://a.example/first', '2026-01-01 10:00:00.000000');
        $this->insertPageTextAction($runId, $stepId, 'https://b.example/second', '2026-01-01 10:01:00.000000');
        $this->insertPageTextAction($runId, $stepId, 'https://a.example/first', '2026-01-01 10:02:00.000000');

        $query = new RunTraceQuery();

        $manifest = $query->consultedSourcesForRun($user->id, $runId);

        $this->assertSame(['https://a.example/first', 'https://b.example/second'], $manifest);

        // Ownership: another user gets nothing.
        $this->assertSame([], $query->consultedSourcesForRun($otherUser->id, $runId));
    }

    #[Test]
    public function a_truncated_envelope_still_yields_its_source_url(): void
    {
        $user = User::factory()->create();

        $runId = (string) Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => $user->id,
            'conversation_id' => null,
            'started_at' => '2026-01-01 09:59:00.000000',
        ]);

        $stepId = (string) Str::uuid();
        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => $runId,
            'position' => 1,
            'started_at' => '2026-01-01 10:00:00.000000',
        ]);

        // What ContentSanitizer::prepare() leaves behind when an envelope
        // exceeds run_trace.action_content_cap_bytes: a valid prefix plus the
        // truncation marker, which json_decode() cannot parse.
        $url = 'https://truncated.example/long-page';
        $truncated = '{"source":{"url":"'.$url.'","title":null},"content":"--- BEGIN RESPONSE UNDER EVALUATION ---'
            ."\n".str_repeat('body ', 50)."\n\n[TRUNCATED: original content exceeded cap]";
        $this->assertNull(json_decode($truncated, true), 'the fixture must really be unparseable JSON');

        DB::table('agent_run_actions')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => 'tool_invocation',
            'target' => 'execute_operation',
            'outcome' => 'success',
            'content' => $truncated,
            'started_at' => '2026-01-01 10:00:00.000000',
        ]);

        $this->assertSame(
            [$url],
            (new RunTraceQuery())->consultedSourcesForRun($user->id, $runId),
            'a source the run consulted must not drop out of the manifest because its envelope was truncated',
        );
    }

    // --- helpers ---

    private function insertPageTextAction(string $runId, string $stepId, string $url, string $startedAt): void
    {
        $envelope = [
            'source' => ['url' => $url, 'title' => null],
            'content' => "--- BEGIN RESPONSE UNDER EVALUATION ---\nbody text\n--- END RESPONSE UNDER EVALUATION ---",
            'reference_id' => null,
        ];

        DB::table('agent_run_actions')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => 'tool_invocation',
            'target' => 'execute_operation',
            'outcome' => 'success',
            'content' => json_encode($envelope),
            'started_at' => $startedAt,
        ]);
    }
}
