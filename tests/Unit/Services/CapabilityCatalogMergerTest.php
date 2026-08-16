<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\CapabilityCatalogMerger;
use ClarionApp\LlmClient\Services\CapabilityOfferingService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use ClarionApp\LlmClient\Services\OperationCache;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the not-yet-built CapabilityCatalogMerger::entriesFor()
 * (109-agent-as-capability, Phase 3/US1, tasks.md T021, data-model.md §5,
 * contracts/capability-agent-call.md's own catalog-entry shape).
 *
 * Mirrors CapabilityOfferingServiceTest.php's own established style and
 * fixture helpers.
 *
 * Written first, confirmed RED: CapabilityCatalogMerger does not exist yet.
 */
class CapabilityCatalogMergerTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_capability_offerings')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers (mirrors CapabilityOfferingServiceTest.php's own fixtures)
    // ---------------------------------------------------------------

    private function merger(): CapabilityCatalogMerger
    {
        return app(CapabilityCatalogMerger::class);
    }

    private function offeringService(): CapabilityOfferingService
    {
        return app(CapabilityOfferingService::class);
    }

    private function agentService(): AgentService
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

    private function seedThreeOperationCatalog(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
            'contacts.index' => ['path' => '/api/contacts', 'method' => 'get', 'summary' => 'List contacts'],
            'weather.get_forecast' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Get forecast'],
        ]);
    }

    private function agent(User $owner, string $name): Agent
    {
        $yaml = <<<YAML
name: {$name}
instructions: Assist customers.
tools:
  allow:
    - "*"
YAML;

        return $this->agentService()->create($owner->id, $yaml);
    }

    private function conversationFor(User $owner, Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Merger test conversation',
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
        ]);
    }

    // ---------------------------------------------------------------
    // entriesFor() -- shape
    // ---------------------------------------------------------------

    #[Test]
    public function entries_for_produces_the_exact_agent_backed_catalog_entry_shape(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'summarizer-agent');
        $caller = $this->agent($owner, 'caller-agent');

        $offering = $this->offeringService()->offer(
            $owner->id,
            $offered->id,
            $caller->id,
            'summarize_document',
            'Produces a concise summary of a supplied document.',
            'The document text to summarize.',
        );

        $conversation = $this->conversationFor($owner, $caller);

        $entries = $this->merger()->entriesFor($conversation);

        $this->assertCount(1, $entries);
        $entry = $entries[0];

        $this->assertSame($offering->id, $entry['operationId'], 'operationId must equal the offering\'s own id -- no separate mapping table');
        $this->assertSame('Produces a concise summary of a supplied document.', $entry['summary']);
        $this->assertSame('AGENT', $entry['method'], 'method must be the sentinel AGENT, never matched by any real HTTP-verb check');
        $this->assertNull($entry['path'], 'path must be null -- never read on this dispatch path');
        $this->assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'input' => [
                        'type' => 'string',
                        'description' => 'The document text to summarize.',
                    ],
                ],
                'required' => ['input'],
            ],
            $entry['paramSchema'],
            'paramSchema must be the fixed one-field synthesized shape (research.md D2)',
        );
    }

    // ---------------------------------------------------------------
    // entriesFor() -- dedicated shape-equality assertion (mutation-
    // checklist row 2): a capability-offering entry and a real operation
    // entry must share an identical key set, no extra field on either side.
    // ---------------------------------------------------------------

    #[Test]
    public function a_capability_offering_entry_and_a_real_operation_entry_share_an_identical_key_set(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'shape-offered-agent');
        $caller = $this->agent($owner, 'shape-caller-agent');

        $this->offeringService()->offer(
            $owner->id, $offered->id, $caller->id,
            'do_thing', 'Does a thing.', 'What to do.',
        );

        $conversation = $this->conversationFor($owner, $caller);

        $offeringEntry = $this->merger()->entriesFor($conversation)[0];

        // A real operation entry, in the exact shape OperationCache::put()
        // stores it and buildKnownOperationsSection() actually reads it --
        // the same "real catalog entries" data-model.md §5 says an offering
        // entry must be indistinguishable from.
        $cache = app(OperationCache::class);
        $cache->put($conversation->id, 'contacts.store', [
            'summary' => 'Store a contact',
            'method' => 'post',
            'path' => '/api/contacts',
            'paramSchema' => ['type' => 'object'],
        ]);
        $realEntry = $cache->get($conversation->id, 'contacts.store');

        $offeringKeys = collect(array_keys($offeringEntry))->sort()->values()->all();
        $realKeys = collect(array_keys($realEntry))->sort()->values()->all();

        $this->assertSame(
            $realKeys,
            $offeringKeys,
            'a capability-offering catalog entry and a real operation entry must share an identical key set, with no extra field (e.g. no is_agent_backed) on either side',
        );
    }

    // ---------------------------------------------------------------
    // entriesFor() -- ineligible offerings excluded
    // ---------------------------------------------------------------

    #[Test]
    public function an_offering_not_eligible_to_the_given_caller_is_excluded(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'excluded-offered-agent');
        $eligibleCaller = $this->agent($owner, 'eligible-caller-agent');
        $otherCaller = $this->agent($owner, 'other-caller-agent');

        $this->offeringService()->offer(
            $owner->id, $offered->id, $eligibleCaller->id,
            'do_thing', 'Does a thing.', 'What to do.',
        );

        // Never offered to $otherCaller -- its conversation must see zero
        // entries.
        $conversation = $this->conversationFor($owner, $otherCaller);

        $entries = $this->merger()->entriesFor($conversation);

        $this->assertSame([], $entries, 'an offering not eligible to the given caller must not appear in its entries');
    }

    #[Test]
    public function a_withdrawn_offering_is_excluded(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'withdrawn-offered-agent');
        $caller = $this->agent($owner, 'withdrawn-caller-agent');

        $this->offeringService()->offer(
            $owner->id, $offered->id, $caller->id,
            'do_thing', 'Does a thing.', 'What to do.',
        );
        $this->offeringService()->withdraw($owner->id, $offered->id, $caller->id);

        $conversation = $this->conversationFor($owner, $caller);

        $entries = $this->merger()->entriesFor($conversation);

        $this->assertSame([], $entries, 'a withdrawn (soft-deleted) offering must not appear in entries');
    }
}
