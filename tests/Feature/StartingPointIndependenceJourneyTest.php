<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\AgentVersion;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Once an agent is created from a starting point, nothing about that
 * starting point can reach it again -- there is no stored link from an
 * Agent/AgentVersion row back to any starting point, so a later edit
 * (or removal) of the source template has nothing to travel through.
 * This guards specifically against a future link column being added
 * and read back at usage time: even with the template file itself
 * edited on disk between two creations, the first agent's stored
 * definition must stay exactly what it was at creation, while a fresh
 * creation from the same slug must pick up the edit -- proving the
 * first agent's stability is real independence, not merely "nothing
 * was re-read since."
 */
class StartingPointIndependenceJourneyTest extends TestCase
{
    private User $user;

    private string $templatePath;

    private string $originalTemplateContent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        $this->templatePath = __DIR__.'/../../src/Templates/research.yaml';
        $this->originalTemplateContent = (string) file_get_contents($this->templatePath);
    }

    protected function tearDown(): void
    {
        // Restore the template unconditionally, even if an assertion
        // above failed -- tearDown() always runs.
        file_put_contents($this->templatePath, $this->originalTemplateContent);

        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function startingPointsUrl(): string
    {
        return '/api/clarion-app/llm-client/agent-starting-points';
    }

    private function agentsUrl(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function agentUrl(string $id): string
    {
        return $this->agentsUrl().'/'.$id;
    }

    /**
     * research.yaml's tools.allow is `GET` plus
     * `clarionApp.llmClient.fetchPage.*` -- seed exactly the two
     * operations that satisfy both patterns, mirroring
     * AgentGenerationFromEveryTemplateTest's own research seeding.
     */
    private function seedOperationCatalog(): void
    {
        $doc = [
            'paths' => [
                '/api/page/text' => [
                    'post' => [
                        'operationId' => 'clarionApp.llmClient.fetchPage.getTextFromUrl',
                        'summary' => 'Fetch the text of a page',
                    ],
                ],
                '/api/conversations' => [
                    'get' => [
                        'operationId' => 'clarionApp.llmClient.conversations.index',
                        'summary' => 'List conversations',
                    ],
                ],
            ],
        ];

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

    #[Test]
    public function an_already_created_agent_is_unaffected_by_a_later_template_edit_or_sibling_agent_edit(): void
    {
        $first = $this->actingAs($this->user)->postJson($this->startingPointsUrl().'/research');
        $first->assertStatus(201);

        $firstAgentId = $first->json('id');
        $firstVersion = AgentVersion::where('agent_id', $firstAgentId)->first();
        $this->assertNotNull($firstVersion, 'the first created agent must have a current version');

        $capturedRawDefinition = $firstVersion->raw_definition;
        $this->assertSame($this->originalTemplateContent, $capturedRawDefinition);

        // Mutate the template on disk after the first agent already exists.
        $editedContent = $this->originalTemplateContent."\n# edited after first creation\n";
        file_put_contents($this->templatePath, $editedContent);

        // The already-created agent's stored definition is completely
        // untouched by the edit -- re-read from the database, not from
        // the in-memory variable captured above.
        $firstVersionAfterEdit = AgentVersion::where('agent_id', $firstAgentId)->first();
        $this->assertSame(
            $capturedRawDefinition,
            $firstVersionAfterEdit->raw_definition,
            'a template edit made after creation must never reach an already-created agent'
        );

        // A second creation from the same slug reflects the edited
        // content -- proving independence is real, not merely "nothing
        // changed because nothing was read again."
        $second = $this->actingAs($this->user)->postJson($this->startingPointsUrl().'/research');
        $second->assertStatus(201);

        $secondAgentId = $second->json('id');
        $this->assertNotSame($firstAgentId, $secondAgentId);

        $secondVersion = AgentVersion::where('agent_id', $secondAgentId)->first();
        $this->assertNotNull($secondVersion, 'the second created agent must have a current version');

        $this->assertSame(
            $editedContent,
            $secondVersion->raw_definition,
            'a second creation must reflect the currently-edited template content'
        );
        $this->assertNotSame(
            $firstVersionAfterEdit->raw_definition,
            $secondVersion->raw_definition,
            'the first and second agents must carry different stored definitions once the template has been edited between the two creations'
        );

        // Editing the first agent through the ordinary agent-update path
        // must not touch the second, independently-created agent.
        $updateResponse = $this->actingAs($this->user)->putJson(
            $this->agentUrl($firstAgentId),
            ['definition' => "name: first-agent-renamed\n"]
        );
        $updateResponse->assertStatus(200);

        $secondVersionAfterUpdate = AgentVersion::where('agent_id', $secondAgentId)->first();
        $this->assertSame(
            $editedContent,
            $secondVersionAfterUpdate->raw_definition,
            'editing the first agent must never alter the second agent created from the same starting point'
        );
    }
}
