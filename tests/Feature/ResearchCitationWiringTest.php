<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\ResearchAgentProvisioner;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature 111 — US1 (FR-003/FR-004): the citation machinery must hold on the
 * seam production actually uses, not only on the pure helper.
 *
 * CitationSurvivalCondensationTest exercises buildPageTextEnvelope() directly
 * and hand-builds agent_run_actions rows. That proves the envelope shape and
 * the manifest derivation in isolation, but it cannot see whether the loop
 * (a) passes the real fetch URL into the envelope, or (b) records the envelope
 * on the streaming path — the path a live conversation takes. Both are load-
 * bearing for contracts §2 ("source.url … always present") and contracts §3
 * ("manifest(run_id) = the page/text action rows' envelope .source.url").
 *
 * This test drives a page/text fetch through AgentLoopStreamHandler with the
 * argument shape the execute_operation schema actually declares
 * (parameters: {path, query, body}) and asserts both ends.
 */
class ResearchCitationWiringTest extends TestCase
{
    private const PAGE_URL = 'https://example.com/research-article';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('llm-client.confirm_methods', []);
        $this->app['config']->set('llm-client.api_denylist', []);

        $this->user = User::factory()->create();

        $this->createSupportingTables();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('mcp_sessions')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    #[Test]
    public function a_page_text_fetch_through_the_loop_carries_its_url_into_the_envelope_and_the_manifest(): void
    {
        $this->seedCatalog();

        $agent = $this->provisioner()->ensureForUser($this->user->id);

        $server = Server::create([
            'name' => 'ResearchServer',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'test-token',
        ]);

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'agent_id' => $agent->id,
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);
        $response->assertStatus(201);
        $conversationId = $response->json('id');

        // Only the outgoing HTTP round trip is mocked. extractArguments() stays
        // real, so the {path, query, body} envelope the execute_operation schema
        // declares is resolved exactly as production resolves it.
        $executorMock = Mockery::mock(
            McpToolExecutor::class.'[executeHttpCall]',
            [$this->app->make(McpToolRegistry::class)],
        );
        $executorMock->shouldReceive('executeHttpCall')->andReturn([
            'content' => [['type' => 'text', 'text' => 'Bees pollinate roughly one third of food crops.']],
            'isError' => false,
        ]);
        $this->app->instance(McpToolExecutor::class, $executorMock);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
            ApiCallConfirmationRequiredEvent::class,
        ]);
        Queue::fake();

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_fetch',
                'type' => 'function',
                'function' => [
                    'name' => 'execute_operation',
                    'arguments' => json_encode([
                        'operationId' => 'clarionApp.llmClient.fetchPage.getTextFromUrl',
                        // The shape buildExecuteOperationSchema() declares for a
                        // POST operation: body fields under `body`.
                        'parameters' => ['body' => ['url' => self::PAGE_URL]],
                    ]),
                ],
            ],
        ];
        $handler->message = Message::create([
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $handler->finish(json_encode([
            'conversation_id' => $conversationId,
            'iteration' => 1,
        ]), 2);

        // (a) The envelope the model sees names the URL that was fetched.
        $handler->message->refresh();
        $toolResults = $handler->message->tool_data['tool_results'] ?? [];
        $this->assertNotEmpty($toolResults, 'the page/text tool call must have produced a result');

        $envelope = json_decode($toolResults[0]['content'] ?? '', true);
        $this->assertIsArray($envelope, 'the page/text result must be a JSON source envelope');
        $this->assertSame(
            self::PAGE_URL,
            $envelope['source']['url'] ?? null,
            'source.url must be the URL actually fetched (contracts §2 — always present, never rewritten)',
        );

        // (b) The derived manifest names it too — on the streaming path.
        $runId = DB::table('agent_runs')->where('user_id', $this->user->id)->value('id');
        $this->assertNotNull($runId, 'the streamed turn must have opened a run');

        $manifest = (new RunTraceQuery())->consultedSourcesForRun($this->user->id, $runId);
        $this->assertSame(
            [self::PAGE_URL],
            $manifest,
            'the consulted-source manifest must list the fetched URL (contracts §3) on the streaming path',
        );
    }

    #[Test]
    public function the_template_requires_naming_sources_and_forbids_citing_one_it_did_not_fetch(): void
    {
        // FR-003/FR-004 (contracts §1's "Name your sources" block). The manifest
        // makes "was this source really consulted?" *checkable*; the instruction
        // is what makes the answer name a source at all, and the only thing
        // standing against a fabricated citation. Nothing else in the template
        // covers either.
        $instructions = (string) (\Symfony\Component\Yaml\Yaml::parseFile(
            __DIR__.'/../../src/Templates/research.yaml',
        )['instructions'] ?? '');

        // Every factual claim carries the source (URL) it rests on (FR-003) ...
        $this->assertMatchesRegularExpression(
            '/cite the source \(url\).*?for every factual claim/is',
            $instructions,
            'the template must require citing the source URL of every factual claim',
        );

        // ... only sources actually fetched may be cited (FR-004) ...
        $this->assertMatchesRegularExpression(
            '/only cite sources you actually fetched/is',
            $instructions,
            'the template must forbid citing a source that was not consulted',
        );
        $this->assertMatchesRegularExpression(
            '/never cite a\s+source you did not consult/is',
            $instructions,
        );

        // ... and the agent is told how it reaches sources at all: the page/text
        // retrieval operation (research.md D4 — there is no search engine, so
        // the agent proposes candidate URLs and verifies each by fetching it).
        $this->assertMatchesRegularExpression(
            '/page\/text/i',
            $instructions,
            'the template must name the retrieval operation the agent fetches with',
        );
    }

    // ---------------------------------------------------------------
    // Helpers (mirroring ResearchAgentDefinitionTest's established harness)
    // ---------------------------------------------------------------

    private function provisioner(): ResearchAgentProvisioner
    {
        return new ResearchAgentProvisioner(
            new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader()),
        );
    }

    private function createSupportingTables(): void
    {
        if (!Schema::hasTable('episodic_memories')) {
            Schema::create('episodic_memories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('conversation_id');
                $table->text('summary');
                $table->json('topics');
                $table->boolean('protected')->default(false);
                $table->unsignedInteger('word_count');
                $table->unsignedInteger('summary_word_count');
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index('user_id');
            });
        }
    }

    private function seedCatalog(): void
    {
        $this->seedOperationCatalog([
            'clarionApp.llmClient.fetchPage.getTextFromUrl' => [
                'path' => '/api/page/text',
                'method' => 'post',
                'summary' => 'Fetch the text of a page',
            ],
            'clarionApp.llmClient.conversations.index' => [
                'path' => '/api/conversations',
                'method' => 'get',
                'summary' => 'List conversations',
            ],
        ]);
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
}
