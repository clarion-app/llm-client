<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /agent-starting-points/{slug} for an unregistered slug --
 * confirms AgentStartingPointNotFoundException is turned into a 404,
 * never mistaken for the 422 this same route returns for an unmet
 * requirement.
 *
 * Written before AgentStartingPointController and the two new routes
 * exist -- expected to fail (Laravel's own route-not-found response, a
 * different shape than the 404 asserted on here) until they are added.
 * That is the intended RED state, not a mistake.
 */
class AgentStartingPointControllerTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        DB::table('users')->delete();
        parent::tearDown();
    }

    private function base(): string
    {
        return '/api/clarion-app/llm-client/agent-starting-points';
    }

    #[Test]
    public function unknown_slug_returns_404_naming_the_slug_and_the_registered_ones(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->base().'/does-not-exist', []);

        $response->assertStatus(404);
        $response->assertJsonPath('code', 'starting_point_not_found');
        $response->assertJsonPath('error', 'Unknown starting point "does-not-exist". Available: research, coding, data, scheduler.');
    }

    #[Test]
    public function unknown_slug_is_never_reported_as_a_422(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->base().'/does-not-exist', []);

        $this->assertNotSame(422, $response->getStatusCode());
        $response->assertStatus(404);
    }
}
