<?php

namespace Tests\Unit\Resume;

use ClarionApp\LlmClient\Services\TaskResumeText;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\OperationCatalogue;
use Tests\TestCase;

/**
 * TaskResumeText — every user-facing word of the resume feature
 * (notices-and-report contract §1, §3).
 *
 * The content rules are binding: no identifiers (a UUID, a tool-call id),
 * no argument values, no provider error text, no HTTP status codes, no
 * percent signs — and each method says what its "Must say" row requires.
 */
class TaskResumeTextTest extends TestCase
{
    /** The fixture provider's error text (ScriptedProvider::transientFailure). */
    private const PROVIDER_ERROR_TEXT = 'Rate limited (scripted)';

    protected function tearDown(): void
    {
        (new OperationCatalogue())->reset();
        parent::tearDown();
    }

    private function text(): TaskResumeText
    {
        return app(TaskResumeText::class);
    }

    /**
     * The NR §3 content rules, applied to one output: no UUID, no
     * call_/toolu_ id, no "http", no 3-digit run that is an HTTP status,
     * no fixture provider error text, no "%".
     */
    private function assertPlainWords(string $output, string $context): void
    {
        $this->assertNotEmpty($output, "{$context}: the text is not empty");
        $this->assertDoesNotMatchRegularExpression(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            $output,
            "{$context}: no UUID",
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\b(call_|toolu_)/i',
            $output,
            "{$context}: no tool-call id prefix",
        );
        $this->assertStringNotContainsStringIgnoringCase('http', $output, "{$context}: no 'http'");
        // A run of exactly three digits (not part of a longer run) in the
        // HTTP status range.
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!\d)[1-5]\d{2}(?!\d)/',
            $output,
            "{$context}: no 3-digit HTTP status",
        );
        $this->assertStringNotContainsString(
            self::PROVIDER_ERROR_TEXT,
            $output,
            "{$context}: no fixture provider error text",
        );
        $this->assertStringNotContainsString('%', $output, "{$context}: no percent sign");
    }

    // ---------------------------------------------------------------
    // outcomeUnknownToolResult — the tool result given to the agent
    // ---------------------------------------------------------------

    #[Test]
    public function outcome_unknown_tool_result_says_what_the_row_requires(): void
    {
        $result = $this->text()->outcomeUnknownToolResult('Write to the ledger (effectful)');

        $this->assertPlainWords($result, 'outcomeUnknownToolResult');
        $this->assertStringContainsStringIgnoringCase('Write to the ledger (effectful)', $result, 'it names the action');
        $this->assertStringContainsStringIgnoringCase('interrupted', $result, 'the action was interrupted while running');
        $this->assertStringContainsStringIgnoringCase('unknown', $result, 'whether it took effect is unknown');
        $this->assertStringContainsStringIgnoringCase('check', $result, 'check before repeating it');
        $this->assertStringContainsStringIgnoringCase('not', $result, 'it was not run again');
    }

    // ---------------------------------------------------------------
    // outcomeUnknownNotice — the conversation notice to the user
    // ---------------------------------------------------------------

    #[Test]
    public function outcome_unknown_notice_says_what_the_row_requires(): void
    {
        $notice = $this->text()->outcomeUnknownNotice(['Write to the ledger (effectful)', 'Clear the stale notes']);

        $this->assertPlainWords($notice, 'outcomeUnknownNotice');
        $this->assertStringContainsStringIgnoringCase('this task', $notice, 'it names "this task"');
        $this->assertStringContainsStringIgnoringCase('interrupted', $notice, 'the task was interrupted while doing the actions');
        $this->assertStringContainsString('Write to the ledger (effectful)', $notice, 'it names the first action');
        $this->assertStringContainsString('Clear the stale notes', $notice, 'it names the second action');
        $this->assertStringContainsStringIgnoringCase('not', $notice, 'it was not repeated');
        $this->assertStringContainsStringIgnoringCase('unknown', $notice, 'the agent has been told the result is unknown');
    }

    // ---------------------------------------------------------------
    // resumedNotice — after a user-visible gap
    // ---------------------------------------------------------------

    #[Test]
    public function resumed_notice_says_what_the_row_requires(): void
    {
        $notice = $this->text()->resumedNotice(8);

        $this->assertPlainWords($notice, 'resumedNotice');
        $this->assertStringContainsStringIgnoringCase('this task', $notice, 'it names "this task"');
        $this->assertStringContainsStringIgnoringCase('interrupted', $notice, 'the task was interrupted');
        $this->assertStringContainsStringIgnoringCase('continuing', $notice, 'it is continuing from where it left off');
        $this->assertStringContainsString('8', $notice, 'it names the step it continues from');
    }

    // ---------------------------------------------------------------
    // providerWaitingNotice — from the 2nd consecutive provider failure
    // ---------------------------------------------------------------

    #[Test]
    public function provider_waiting_notice_says_what_the_row_requires(): void
    {
        $notice = $this->text()->providerWaitingNotice(Carbon::now()->addMinutes(10));

        $this->assertPlainWords($notice, 'providerWaitingNotice');
        $this->assertStringContainsStringIgnoringCase('retry', $notice, 'waiting to retry the model provider');
        $this->assertStringContainsStringIgnoringCase('next attempt', $notice, 'it says when the next attempt is');
    }

    // ---------------------------------------------------------------
    // continuingText — the 202-continuing sync response content
    // ---------------------------------------------------------------

    #[Test]
    public function continuing_text_says_what_the_row_requires(): void
    {
        $text = $this->text()->continuingText(Carbon::now()->addMinutes(10));

        $this->assertPlainWords($text, 'continuingText');
        $this->assertStringContainsStringIgnoringCase('still working', $text, 'the task is still working');
        $this->assertStringContainsStringIgnoringCase('reply will arrive', $text, 'its reply will arrive here');
        // US3 AC1: a single blip shows no failure.
        $this->assertStringNotContainsStringIgnoringCase('fail', $text, 'it must not mention a failure');
        $this->assertStringNotContainsStringIgnoringCase('error', $text, 'it must not mention an error');
        $this->assertStringNotContainsStringIgnoringCase('provider', $text, 'it must not mention the provider');
    }

    // ---------------------------------------------------------------
    // providerUnavailableReason / providerUnavailableReport
    // ---------------------------------------------------------------

    #[Test]
    public function provider_unavailable_reason_says_what_the_row_requires(): void
    {
        $reason = $this->text()->providerUnavailableReason();

        $this->assertPlainWords($reason, 'providerUnavailableReason');
        $this->assertLessThanOrEqual(256, strlen($reason), 'the end_reason fits its column');
        $this->assertStringContainsStringIgnoringCase('unavailable', $reason, 'the model provider stayed unavailable');
        $this->assertStringContainsStringIgnoringCase('no further', $reason, 'no further automatic attempts');
    }

    #[Test]
    public function provider_unavailable_report_says_what_the_row_requires(): void
    {
        $report = $this->text()->providerUnavailableReport(4);

        $this->assertPlainWords($report, 'providerUnavailableReport');
        $this->assertStringContainsStringIgnoringCase('unavailable', $report, 'as the reason row');
        $this->assertStringContainsString('4', $report, 'it says how many steps were completed');
        $this->assertStringContainsStringIgnoringCase('kept', $report, 'the completed steps are kept');
    }

    // ---------------------------------------------------------------
    // couldNotResumeReason — one sentence per reason code
    // ---------------------------------------------------------------

    #[Test]
    public function could_not_resume_reason_says_what_the_row_requires(): void
    {
        $cases = [
            'repeated_interruption',
            'checkpoint_incoherent:two_open_steps',
            'legacy_insufficient',
            'model_unavailable',
            'tool_unavailable',
            'superseded',
            'ended_without_outcome',
        ];

        foreach ($cases as $code) {
            $reason = $this->text()->couldNotResumeReason($code);
            $this->assertPlainWords($reason, "couldNotResumeReason({$code})");
            $this->assertLessThanOrEqual(256, strlen($reason), "the end_reason fits its column ({$code})");
            $this->assertStringContainsStringIgnoringCase('this task', $reason, "it names 'this task' ({$code})");
        }

        $incoherent = $this->text()->couldNotResumeReason('checkpoint_incoherent:two_open_steps');
        $this->assertStringContainsStringIgnoringCase('could not be read safely', $incoherent, 'checkpoint_incoherent says the saved progress could not be read safely');

        $legacy = $this->text()->couldNotResumeReason('legacy_insufficient');
        $this->assertStringContainsStringIgnoringCase('before this version', $legacy, 'legacy_insufficient says it was started before this version');
    }

    // ---------------------------------------------------------------
    // couldNotResumeReport — the channel report
    // ---------------------------------------------------------------

    #[Test]
    public function could_not_resume_report_says_what_the_row_requires(): void
    {
        $report = $this->text()->couldNotResumeReport('ended_without_outcome', 3, 'the third step');

        $this->assertPlainWords($report, 'couldNotResumeReport');
        $this->assertStringContainsString('3', $report, 'what was completed: the steps kept in the conversation');
        $this->assertStringContainsStringIgnoringCase('kept', $report, 'the completed steps are kept');
        $this->assertStringContainsStringIgnoringCase('stopped', $report, 'where it stopped');
        $this->assertStringContainsStringIgnoringCase('nothing more', $report, 'nothing more will run automatically');
        $this->assertStringContainsStringIgnoringCase('new request', $report, 'a new request is welcome');
    }

    // ---------------------------------------------------------------
    // cancelledReason
    // ---------------------------------------------------------------

    #[Test]
    public function cancelled_reason_says_what_the_row_requires(): void
    {
        $reason = $this->text()->cancelledReason();

        $this->assertPlainWords($reason, 'cancelledReason');
        $this->assertLessThanOrEqual(256, strlen($reason), 'the end_reason fits its column');
        $this->assertStringContainsStringIgnoringCase('cancelled', $reason, 'the task was cancelled');
    }

    // ---------------------------------------------------------------
    // actionLabel — registry title, then operation id, then tool name;
    // never argument values
    // ---------------------------------------------------------------

    #[Test]
    public function action_label_prefers_the_registry_title_annotation(): void
    {
        $catalogue = new OperationCatalogue();
        $catalogue->seed([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Label API', 'version' => '1.0.0'],
            'paths' => [
                '/notes' => [
                    'post' => [
                        'operationId' => 'storeNote',
                        'summary' => 'Store a note',
                        'parameters' => [],
                        'responses' => ['200' => ['description' => 'Created']],
                    ],
                ],
            ],
        ]);

        $label = $this->text()->actionLabel('execute_operation', ['operationId' => 'storeNote']);

        $this->assertSame('Store a note', $label, 'the registry title annotation wins');
        $this->assertPlainWords($label, 'actionLabel (title)');
    }

    #[Test]
    public function action_label_falls_back_to_the_operation_id(): void
    {
        $label = $this->text()->actionLabel('execute_operation', ['operationId' => 'clearStaleNotes']);

        $this->assertSame('clearStaleNotes', $label, 'without a registry title the operation id is the label');
    }

    #[Test]
    public function action_label_falls_back_to_the_tool_name(): void
    {
        $label = $this->text()->actionLabel('search_operations', ['query' => 'notes']);

        $this->assertSame('search_operations', $label, 'without an operation id the tool name is the label');
    }

    #[Test]
    public function action_label_never_includes_argument_values(): void
    {
        $label = $this->text()->actionLabel(
            'execute_operation',
            ['operationId' => 'storeNote', 'parameters' => ['note' => 'a secret argument value']],
        );

        $this->assertStringNotContainsString('a secret argument value', $label, 'argument values never appear');
        $this->assertStringNotContainsString('secret', $label, 'argument values never appear');
    }
}
