<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\ConsensusReconciliationPromptBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 104-multi-agent-consensus, Phase 3 (US1), tasks.md T013.
 *
 * Pure, deterministic prompt construction -- no I/O, no provider call, no
 * randomness -- mirroring RubricJudgmentPromptBuilder's own shape
 * (Grounding note item 3, contracts/consensus-reconciliation-contract.md
 * §2). buildMessages(string $question, array $contributorAnswers): array
 * must: include the question text, every contributor's answer keyed by its
 * own delegation_id, the fixed approximation-caveat disclaimer (research.md
 * D3 -- "this is an approximation, not a guarantee") in the system
 * instruction, and request the {classification, reconciled_answer,
 * positions} JSON shape.
 *
 * Written before ConsensusReconciliationPromptBuilder exists -- every
 * assertion below is expected to FAIL red (class not found) until T018
 * creates it.
 */
class ConsensusReconciliationPromptBuilderTest extends TestCase
{
    private function builder(): ConsensusReconciliationPromptBuilder
    {
        return app(ConsensusReconciliationPromptBuilder::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function contentForRole(array $messages, string $role): string
    {
        $message = collect($messages)->firstWhere('role', $role);

        $this->assertNotNull($message, "no {$role} message was built");

        return (string) $message['content'];
    }

    /** @return array<int, array{delegation_id: string, helper_agent_id: string, answer: string}> */
    private function threeContributors(): array
    {
        return [
            ['delegation_id' => 'dlg_aaa', 'helper_agent_id' => 'agt_aaa', 'answer' => 'Yes, this is safe to run.'],
            ['delegation_id' => 'dlg_bbb', 'helper_agent_id' => 'agt_bbb', 'answer' => 'Safe, provided a backup is taken first.'],
            ['delegation_id' => 'dlg_ccc', 'helper_agent_id' => 'agt_ccc', 'answer' => 'This carries real risk during business hours.'],
        ];
    }

    #[Test]
    public function the_question_text_is_included_verbatim(): void
    {
        $question = 'Is it safe to run this migration against the production replica during business hours?';

        $messages = $this->builder()->buildMessages($question, $this->threeContributors());

        $all = implode("\n", array_map(fn (array $m) => (string) $m['content'], $messages));
        $this->assertStringContainsString($question, $all);
    }

    #[Test]
    public function every_contributor_answer_is_included_keyed_by_its_own_delegation_id(): void
    {
        $contributors = $this->threeContributors();

        $messages = $this->builder()->buildMessages('A question.', $contributors);

        $all = implode("\n", array_map(fn (array $m) => (string) $m['content'], $messages));

        foreach ($contributors as $contributor) {
            $this->assertStringContainsString($contributor['delegation_id'], $all, "delegation_id {$contributor['delegation_id']} must key its own answer");
            $this->assertStringContainsString($contributor['answer'], $all, "the answer text for {$contributor['delegation_id']} must be included verbatim");
        }
    }

    #[Test]
    public function the_system_instruction_states_the_approximation_caveat(): void
    {
        $messages = $this->builder()->buildMessages('A question.', $this->threeContributors());

        $system = $this->contentForRole($messages, 'system');

        $this->assertStringContainsStringIgnoringCase('approximation', $system);
        $this->assertStringContainsStringIgnoringCase('not a guarantee', $system);
    }

    #[Test]
    public function the_system_instruction_requests_the_required_json_shape(): void
    {
        $messages = $this->builder()->buildMessages('A question.', $this->threeContributors());

        $system = $this->contentForRole($messages, 'system');

        $this->assertStringContainsStringIgnoringCase('json', $system);
        $this->assertStringContainsStringIgnoringCase('classification', $system);
        $this->assertStringContainsStringIgnoringCase('reconciled_answer', $system);
        $this->assertStringContainsStringIgnoringCase('positions', $system);
        $this->assertStringContainsStringIgnoringCase('agreed', $system);
        $this->assertStringContainsStringIgnoringCase('materially_disagreed', $system);
        $this->assertStringContainsStringIgnoringCase('no_consensus', $system);
    }

    #[Test]
    public function returns_exactly_a_system_and_a_user_message(): void
    {
        $messages = $this->builder()->buildMessages('A question.', $this->threeContributors());

        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
    }
}
