<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Exceptions\AgentFileUnreadableException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentDivergenceChecker;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use ClarionApp\LlmClient\ValueObjects\DivergenceState;
use ClarionApp\LlmClient\ValueObjects\FileDivergenceReport;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for AgentDivergenceChecker::check() (Phase 5/US3,
 * data-model.md §4, contracts §12, research.md D9/D10) — using a fake/
 * mocked GitDefinitionFileReader (this is a Unit test; no real git repo
 * needed here, unlike GitDefinitionFileReaderTest).
 *
 * Design note (resolved while authoring this file, not a production
 * decision): tasks.md's own T039 summary sentence ("governs is always the
 * literal 'stored_agent' string whenever state !== NotLinked") read
 * literally would also give Unavailable a non-null governs. But T044 (the
 * task that actually specifies what AgentDivergenceChecker is to do)
 * says explicitly: "catching AgentFileUnreadableException into state =
 * Unavailable/governs = null/unavailableReason set" — and contracts §10's
 * own worked example agrees: {"state": "unavailable", "governs": null,
 * ...}. This file follows T044 and contracts §10 (the two sources that
 * describe the actual behavior to build), treating T039's summary
 * sentence as an imprecise paraphrase covering the four "linked and
 * successfully checked" states, not a fifth requirement contradicting the
 * other two design docs.
 */
class AgentDivergenceCheckerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function checker(GitDefinitionFileReader $reader): AgentDivergenceChecker
    {
        return new AgentDivergenceChecker($reader);
    }

    private function makeAgent(array $overrides = []): Agent
    {
        $user = User::factory()->create();

        return Agent::create(array_merge([
            'user_id' => $user->id,
            'name' => 'divergence-test-agent',
            'current_version_id' => null,
            'linked_repository_path' => null,
            'linked_file_path' => null,
            'linked_synced_file_hash' => null,
        ], $overrides));
    }

    private function attachCurrentVersion(Agent $agent, string $contentHash, string $rawDefinition = 'name: divergence-test-agent'): AgentVersion
    {
        $version = AgentVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 1,
            'raw_definition' => $rawDefinition,
            'content_hash' => $contentHash,
            'source' => AgentChangeSource::Created->value,
            'changed_by_user_id' => $agent->user_id,
        ]);

        $agent->current_version_id = $version->id;
        $agent->save();

        return $version;
    }

    // ---------------------------------------------------------------
    // NotLinked
    // ---------------------------------------------------------------

    #[Test]
    public function reports_not_linked_when_the_agent_has_no_linked_file(): void
    {
        $agent = $this->makeAgent();
        $this->attachCurrentVersion($agent, hash('sha256', 'name: divergence-test-agent'));

        $reader = Mockery::mock(GitDefinitionFileReader::class);
        $reader->shouldNotReceive('readWorkingTreeContent');

        $report = $this->checker($reader)->check($agent->fresh());

        $this->assertInstanceOf(FileDivergenceReport::class, $report);
        $this->assertSame(DivergenceState::NotLinked, $report->state);
        $this->assertNull($report->governs);
    }

    // ---------------------------------------------------------------
    // InStep
    // ---------------------------------------------------------------

    #[Test]
    public function reports_in_step_when_neither_side_has_moved_past_the_synced_baseline(): void
    {
        $baselineContent = "name: file-agent\n";
        $baselineHash = hash('sha256', $baselineContent);

        $agent = $this->makeAgent([
            'linked_repository_path' => '/tmp/does-not-matter',
            'linked_file_path' => 'agent.yaml',
            'linked_synced_file_hash' => $baselineHash,
        ]);
        $this->attachCurrentVersion($agent, $baselineHash);

        $reader = Mockery::mock(GitDefinitionFileReader::class);
        $reader->shouldReceive('readWorkingTreeContent')
            ->once()
            ->with('/tmp/does-not-matter', 'agent.yaml')
            ->andReturn($baselineContent);

        $report = $this->checker($reader)->check($agent->fresh());

        $this->assertSame(DivergenceState::InStep, $report->state);
        $this->assertSame('stored_agent', $report->governs);
    }

    // ---------------------------------------------------------------
    // FileAhead
    // ---------------------------------------------------------------

    #[Test]
    public function reports_file_ahead_when_the_file_changed_and_the_stored_agent_did_not(): void
    {
        $baselineContent = "name: file-agent\n";
        $baselineHash = hash('sha256', $baselineContent);
        $editedContent = "name: file-agent-edited\n";

        $agent = $this->makeAgent([
            'linked_repository_path' => '/tmp/does-not-matter',
            'linked_file_path' => 'agent.yaml',
            'linked_synced_file_hash' => $baselineHash,
        ]);
        $this->attachCurrentVersion($agent, $baselineHash);

        $reader = Mockery::mock(GitDefinitionFileReader::class);
        $reader->shouldReceive('readWorkingTreeContent')->once()->andReturn($editedContent);

        $report = $this->checker($reader)->check($agent->fresh());

        $this->assertSame(DivergenceState::FileAhead, $report->state);
        $this->assertSame('stored_agent', $report->governs);
    }

    // ---------------------------------------------------------------
    // StoredAhead — the tricky one (mutation-checklist row 7). The file's
    // live hash still equals the *baseline*, but current_version's
    // content_hash has moved past it. A naive "compare the live file hash
    // directly against current_version's content_hash" implementation
    // would see the two now differ and could misreport this scenario
    // instead of correctly attributing the drift to the stored side —
    // only comparing *both* sides against the linked_synced_file_hash
    // baseline (research.md D9) gets this right.
    // ---------------------------------------------------------------

    #[Test]
    public function reports_stored_ahead_when_the_stored_agent_changed_and_the_file_did_not(): void
    {
        $baselineContent = "name: file-agent\n";
        $baselineHash = hash('sha256', $baselineContent);
        $newStoredHash = hash('sha256', 'name: stored-agent-edited');

        $agent = $this->makeAgent([
            'linked_repository_path' => '/tmp/does-not-matter',
            'linked_file_path' => 'agent.yaml',
            'linked_synced_file_hash' => $baselineHash,
        ]);
        // The stored current version has moved past the baseline...
        $this->attachCurrentVersion($agent, $newStoredHash, 'name: stored-agent-edited');

        $reader = Mockery::mock(GitDefinitionFileReader::class);
        // ...but the file on disk still holds exactly the baseline content.
        $reader->shouldReceive('readWorkingTreeContent')->once()->andReturn($baselineContent);

        $report = $this->checker($reader)->check($agent->fresh());

        $this->assertSame(
            DivergenceState::StoredAhead,
            $report->state,
            'the file is unchanged from the baseline; only the stored side moved — must report StoredAhead, never InStep or FileAhead'
        );
        $this->assertSame('stored_agent', $report->governs);
    }

    // ---------------------------------------------------------------
    // BothChanged
    // ---------------------------------------------------------------

    #[Test]
    public function reports_both_changed_when_both_sides_moved_independently(): void
    {
        $baselineContent = "name: file-agent\n";
        $baselineHash = hash('sha256', $baselineContent);
        $editedContent = "name: file-agent-edited\n";
        $newStoredHash = hash('sha256', 'name: stored-agent-edited');

        $agent = $this->makeAgent([
            'linked_repository_path' => '/tmp/does-not-matter',
            'linked_file_path' => 'agent.yaml',
            'linked_synced_file_hash' => $baselineHash,
        ]);
        $this->attachCurrentVersion($agent, $newStoredHash, 'name: stored-agent-edited');

        $reader = Mockery::mock(GitDefinitionFileReader::class);
        $reader->shouldReceive('readWorkingTreeContent')->once()->andReturn($editedContent);

        $report = $this->checker($reader)->check($agent->fresh());

        $this->assertSame(DivergenceState::BothChanged, $report->state);
        $this->assertSame('stored_agent', $report->governs);
    }

    // ---------------------------------------------------------------
    // Unavailable
    // ---------------------------------------------------------------

    #[Test]
    public function reports_unavailable_when_the_reader_cannot_read_the_live_file(): void
    {
        $baselineHash = hash('sha256', "name: file-agent\n");

        $agent = $this->makeAgent([
            'linked_repository_path' => '/tmp/does-not-matter',
            'linked_file_path' => 'agent.yaml',
            'linked_synced_file_hash' => $baselineHash,
        ]);
        $this->attachCurrentVersion($agent, $baselineHash);

        $reader = Mockery::mock(GitDefinitionFileReader::class);
        $reader->shouldReceive('readWorkingTreeContent')
            ->once()
            ->andThrow(new AgentFileUnreadableException('file missing'));

        $report = $this->checker($reader)->check($agent->fresh());

        $this->assertSame(DivergenceState::Unavailable, $report->state);
        $this->assertNull($report->governs, "an unreadable file must report governs = null, matching contracts §10's own worked example");
        $this->assertNotNull($report->unavailableReason);
    }
}
