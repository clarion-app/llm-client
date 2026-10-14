<?php

namespace Tests\Feature\Resume;

use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Services\DelegationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\AssembledSystemTestCase;
use Tests\Integration\Harness\TaskBudgetHelperJourneys;

/**
 * A delegation row carries the provider's call id in
 * `parent_tool_call_id` (checkpoint-ledger contract §5), so the delegation
 * can be joined to the checkpoint's call entry. A solo delegate_to_helper
 * through the funnel is stamped from Context('tool_call_id') (the target of
 * quickstart M22); a capability call is stamped the same way; each member of
 * a batch is stamped with its own call id (one Context value cannot serve a
 * batch, so the id is taken from each call); a 300-character id is cut to
 * 255; and a delegation created outside a tool call has no parent call id.
 */
class DelegationParentToolCallIdTest extends AssembledSystemTestCase
{
    use TaskBudgetHelperJourneys;

    private const USAGE = [10, 10];

    /** @return object the delegation row for the parent conversation */
    private function delegationOf(string $parentConversationId): object
    {
        $row = DB::table('agent_delegations')->where('parent_conversation_id', $parentConversationId)->first();
        $this->assertNotNull($row, 'a delegation row exists');

        return $row;
    }

    private function setupHelper(): void
    {
        $this->setUpHelperJourney();
        $this->priceModel();
        $this->fixtureWithModel();
    }

    // ---------------------------------------------------------------
    // A solo delegate_to_helper through the funnel is stamped from
    // Context('tool_call_id') (quickstart M22)
    // ---------------------------------------------------------------

    #[Test]
    public function a_solo_delegation_is_stamped_with_the_call_id(): void
    {
        $this->scenario = 'delegation_parent_tool_call_id_solo';
        $this->setupHelper();
        $root = $this->agent('root');
        $a = $this->agent('a');
        $this->assignHelper($root, $a);

        $conversation = $this->rootConversation('sync', $root);
        $this->script()->toolRequests([
            ['id' => 'call_to_a', 'name' => 'delegate_to_helper', 'arguments' => ['helper_agent_id' => $a->id, 'task' => 'Part one.']],
        ])->usage(...self::USAGE);
        $this->scriptHelperAnswer('Part one done.', self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        $this->send('sync', $conversation, 'Do the part.')->assertStatus(200);

        $row = $this->delegationOf($conversation->id);
        $this->assertSame('call_to_a', $row->parent_tool_call_id, 'the solo delegation is stamped with the call id');
        $this->tearDownTaskBudgetJourney();
    }

    // ---------------------------------------------------------------
    // A capability call is stamped the same way
    // ---------------------------------------------------------------

    #[Test]
    public function a_capability_call_is_stamped_with_the_call_id(): void
    {
        $this->scenario = 'delegation_parent_tool_call_id_capability';
        $this->setupHelper();
        $root = $this->agent('root');
        $a = $this->agent('a');
        $this->assignHelper($root, $a);

        // A capability offering: the execute_operation whose operationId is
        // the offering's id is a capability call.
        $offering = CapabilityOffering::create([
            'id' => (string) Str::uuid(),
            'offered_agent_id' => $a->id,
            'caller_agent_id' => $root->id,
            'owner_user_id' => (string) $this->fixture()->user->id,
            'capability_name' => 'summarise',
            'capability_description' => 'Summarises a document.',
            'input_description' => 'The document text.',
        ]);

        $conversation = $this->rootConversation('sync', $root);
        $this->script()->toolRequests([
            ['id' => 'call_capability', 'name' => 'execute_operation', 'arguments' => ['operationId' => $offering->id]],
        ])->usage(...self::USAGE);
        $this->scriptHelperAnswer('Summarised.', self::USAGE);
        // No separate root final answer: the capability result ends the turn.

        $this->send('sync', $conversation, 'Summarise the document.')->assertStatus(200);

        $row = $this->delegationOf($conversation->id);
        $this->assertSame('call_capability', $row->parent_tool_call_id, 'the capability call is stamped with the call id');
        $this->tearDownTaskBudgetJourney();
    }

    // ---------------------------------------------------------------
    // Each member of a 3-call batch is stamped with its own call id
    // ---------------------------------------------------------------

    #[Test]
    public function each_batch_member_is_stamped_with_its_own_call_id(): void
    {
        $this->scenario = 'delegation_parent_tool_call_id_batch';
        $this->setupHelper();
        $root = $this->agent('root');
        $a = $this->agent('a');
        $b = $this->agent('b');
        $c = $this->agent('c');
        $this->assignHelper($root, $a);
        $this->assignHelper($root, $b);
        $this->assignHelper($root, $c);

        $conversation = $this->rootConversation('sync', $root);
        $this->script()->toolRequests([
            ['id' => 'call_to_a', 'name' => 'delegate_to_helper', 'arguments' => ['helper_agent_id' => $a->id, 'task' => 'Part one.']],
            ['id' => 'call_to_b', 'name' => 'delegate_to_helper', 'arguments' => ['helper_agent_id' => $b->id, 'task' => 'Part two.']],
            ['id' => 'call_to_c', 'name' => 'delegate_to_helper', 'arguments' => ['helper_agent_id' => $c->id, 'task' => 'Part three.']],
        ])->usage(...self::USAGE);
        $this->scriptHelperAnswer('Part one done.', self::USAGE);
        $this->scriptHelperAnswer('Part two done.', self::USAGE);
        $this->scriptHelperAnswer('Part three done.', self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        $this->send('sync', $conversation, 'Do all three parts.')->assertStatus(200);

        // Each member is stamped with its own call id (taken from each call,
        // since one Context value cannot serve a batch).
        $rows = DB::table('agent_delegations')->where('parent_conversation_id', $conversation->id)->get();
        $this->assertCount(3, $rows, 'three delegations, one per member');
        $byHelper = [];
        foreach ($rows as $row) {
            $byHelper[$row->helper_agent_id] = $row->parent_tool_call_id;
        }
        $this->assertSame('call_to_a', $byHelper[$a->id] ?? null, 'member a is stamped with its own call id');
        $this->assertSame('call_to_b', $byHelper[$b->id] ?? null, 'member b is stamped with its own call id');
        $this->assertSame('call_to_c', $byHelper[$c->id] ?? null, 'member c is stamped with its own call id');
        $this->tearDownTaskBudgetJourney();
    }

    // ---------------------------------------------------------------
    // A 300-character call id is cut to 255
    // ---------------------------------------------------------------

    #[Test]
    public function a_300_character_call_id_is_cut_to_255(): void
    {
        $this->scenario = 'delegation_parent_tool_call_id_cut';
        $this->setupHelper();
        $root = $this->agent('root');
        $a = $this->agent('a');
        $this->assignHelper($root, $a);

        $conversation = $this->rootConversation('sync', $root);
        $longId = str_repeat('x', 300);
        $this->script()->toolRequests([
            ['id' => $longId, 'name' => 'delegate_to_helper', 'arguments' => ['helper_agent_id' => $a->id, 'task' => 'Part one.']],
        ])->usage(...self::USAGE);
        $this->scriptHelperAnswer('Part one done.', self::USAGE);
        $this->script()->finalAnswer('Done.')->usage(...self::USAGE);

        $this->send('sync', $conversation, 'Do the part.')->assertStatus(200);

        $row = $this->delegationOf($conversation->id);
        $this->assertSame(255, strlen((string) $row->parent_tool_call_id), 'the 300-character call id is cut to 255');
        $this->tearDownTaskBudgetJourney();
    }

    // ---------------------------------------------------------------
    // A delegation created outside a tool call has no parent call id
    // ---------------------------------------------------------------

    #[Test]
    public function a_delegation_created_outside_a_tool_call_has_no_parent_call_id(): void
    {
        $this->scenario = 'delegation_parent_tool_call_id_outside';
        $this->setupHelper();
        $root = $this->agent('root');
        $a = $this->agent('a');
        $this->assignHelper($root, $a);

        $conversation = $this->rootConversation('sync', $root);
        // Script the helper's answer so the nested run completes on its
        // primary route (no undeclared degradation).
        $this->scriptHelperAnswer('Part one done.', self::USAGE);
        // Call delegate() directly, bypassing the funnel: no
        // Context('tool_call_id') is set, so the delegation is not stamped.
        app(DelegationService::class)->delegate($conversation, $a->id, 'A direct delegation.', null);

        $row = $this->delegationOf($conversation->id);
        $this->assertNull($row->parent_tool_call_id, 'a delegation created outside a tool call has no parent call id');
        $this->tearDownTaskBudgetJourney();
    }
}
