<?php

namespace ClarionApp\LlmClient\Tests\Integration;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\DelegationUpdated;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\DelegationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * 106-multi-agent-run-view, Phase 4 (US2), tasks.md T026 (research.md D2,
 * mirrors RunLiveUpdateNonLeakTest.php, 070's own precedent for the
 * identical concern).
 *
 * User A's in-progress delegation fires DelegationUpdated as
 * DelegationService records terminal/administrative writes against it.
 * Event::fake() intercepts the actual broadcast (no live Pusher connection
 * needed) while still constructing each real event object, so this test can
 * call the event's own broadcastOn() directly inside the assertion callback
 * and confirm it always resolves to user A's private channel -- and never to
 * user B's, a real, distinct, authenticated user created in this same test
 * -- for every dispatched instance.
 */
class DelegationLiveUpdateNonLeakTest extends TestCase
{
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defineAgentDelegationSchema();

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
    }

    protected function tearDown(): void
    {
        DB::table('agent_delegations')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function makeDelegation(User $owner, string $status, ?string $batchId = null): Delegation
    {
        return Delegation::create([
            'parent_conversation_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $owner->id,
            'task' => 'A live-update non-leak fixture delegation.',
            'depth' => 1,
            'status' => $status,
            'batch_id' => $batchId,
            'started_at' => now(),
        ]);
    }

    #[Test]
    public function a_terminal_write_for_user_as_delegation_resolves_only_to_users_as_channel_never_users_bs(): void
    {
        Event::fake([DelegationUpdated::class]);

        $delegationA = $this->makeDelegation($this->userA, 'in_progress');

        app(DelegationService::class)->recordBatchMemberTimeoutOrFailure($delegationA->id, new \RuntimeException('boom'));

        $ownerChannel = 'private-User.' . $this->userA->id;
        $foreignChannel = 'private-User.' . $this->userB->id;

        Event::assertDispatched(DelegationUpdated::class, function (DelegationUpdated $event) use ($delegationA, $ownerChannel, $foreignChannel) {
            if ($event->delegationId !== $delegationA->id) {
                return false;
            }
            $names = array_map(fn ($c) => (string) $c, $event->broadcastOn());

            return $names === [$ownerChannel] && !in_array($foreignChannel, $names, true);
        });
    }

    #[Test]
    public function user_b_is_never_named_by_broadcast_on_even_when_user_b_also_has_a_concurrent_in_progress_delegation(): void
    {
        // The strongest version of the non-leak guarantee: user B is not
        // merely absent, they have their own concurrent, in-progress
        // delegation -- proving isolation is structural (per-owner channel
        // resolution), not merely "there was nothing to leak."
        Event::fake([DelegationUpdated::class]);

        $delegationA = $this->makeDelegation($this->userA, 'in_progress');
        $delegationB = $this->makeDelegation($this->userB, 'in_progress');

        $service = app(DelegationService::class);
        $service->forceFinalizeBatchJoinTimeout($delegationA->fresh());
        $service->forceFinalizeBatchJoinTimeout($delegationB->fresh());

        $ownerAChannel = 'private-User.' . $this->userA->id;
        $ownerBChannel = 'private-User.' . $this->userB->id;

        Event::assertDispatched(DelegationUpdated::class, function (DelegationUpdated $event) use ($delegationA, $ownerAChannel, $ownerBChannel) {
            if ($event->delegationId !== $delegationA->id) {
                return false;
            }
            $names = array_map(fn ($c) => (string) $c, $event->broadcastOn());

            return $names === [$ownerAChannel] && !in_array($ownerBChannel, $names, true);
        });

        Event::assertDispatched(DelegationUpdated::class, function (DelegationUpdated $event) use ($delegationB, $ownerAChannel, $ownerBChannel) {
            if ($event->delegationId !== $delegationB->id) {
                return false;
            }
            $names = array_map(fn ($c) => (string) $c, $event->broadcastOn());

            return $names === [$ownerBChannel] && !in_array($ownerAChannel, $names, true);
        });
    }

    #[Test]
    public function every_dispatched_delegation_updated_instance_individually_excludes_the_other_users_channel(): void
    {
        Event::fake([DelegationUpdated::class]);

        $delegationA1 = $this->makeDelegation($this->userA, 'in_progress');
        $delegationA2 = $this->makeDelegation($this->userA, 'in_progress');

        $service = app(DelegationService::class);
        $service->forceFinalizeBatchJoinTimeout($delegationA1->fresh());
        $service->forceFinalizeBatchJoinTimeout($delegationA2->fresh());

        $ownerChannel = 'private-User.' . $this->userA->id;
        $foreignChannel = 'private-User.' . $this->userB->id;

        Event::assertDispatchedTimes(DelegationUpdated::class, 2);

        Event::assertDispatched(DelegationUpdated::class, function (DelegationUpdated $event) use ($ownerChannel, $foreignChannel) {
            $names = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            $this->assertNotContains($foreignChannel, $names);
            $this->assertSame([$ownerChannel], $names);

            return true;
        });
    }
}
