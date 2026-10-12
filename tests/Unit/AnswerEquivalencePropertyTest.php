<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\Services\InteractiveAnswerService;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An answer means the same thing however it is given. A selection made by
 * form or tap is turned into the reply the user could have typed for it and
 * interpreted by the same parser, so for every question shape (1 to 10
 * choices, single- and multi-select) and every valid selection, the
 * interactive answer and each typed spelling of the same selection
 * ("1, 3", "1 3", "3,1", "3 1 3") select exactly the same choices; only the
 * raw reply differs. Free text is interpreted exactly as the same text
 * typed — including text that happens to look like a choice number.
 *
 * Both halves are checked twice: against the parser directly (the
 * property), and through InteractiveAnswerService::record() (what the
 * service actually stores and the agent receives), so an interactive path
 * that bypasses the parser cannot hold the property by accident.
 */
class AnswerEquivalencePropertyTest extends TestCase
{
    /** Question shapes whose every selection is also recorded through the service. */
    private const RECORDED_UP_TO_CHOICES = 6;

    private const FREE_TEXTS = [
        '2',
        '2.',
        '#2',
        ' 2 ',
        '1, 3',
        '0',
        '11',
        'none',
        'none of these — use a fourth approach',
        "  keep my spacing\n",
    ];

    private ?Conversation $conversation = null;

    protected function tearDown(): void
    {
        if (Schema::hasTable('agent_questions')) {
            DB::table('agent_questions')->delete();
        }
        DB::table('messages')->delete();
        DB::table('conversations')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /** @return list<string> */
    private static function choices(int $count): array
    {
        return array_map(fn (int $n) => "Choice number {$n}", range(1, $count));
    }

    private function question(int $choiceCount, bool $allowMultiple): AgentQuestion
    {
        return (new AgentQuestion())->forceFill([
            'question' => 'Which ones?',
            'choices' => $choiceCount === 0 ? [] : self::choices($choiceCount),
            'allow_multiple' => $allowMultiple,
        ]);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function questionShapes(): iterable
    {
        foreach (range(1, 10) as $count) {
            yield "{$count} choices, single-select" => [$count, false];
            yield "{$count} choices, multi-select" => [$count, true];
        }
    }

    /**
     * Every non-empty selection a question of this shape accepts: each
     * single choice on a single-select question, every non-empty subset on
     * a multi-select one. Ascending.
     *
     * @return list<list<int>>
     */
    private static function validSelections(int $count, bool $allowMultiple): array
    {
        if (!$allowMultiple) {
            return array_map(fn (int $n) => [$n], range(1, $count));
        }

        $subsets = [];
        for ($mask = 1; $mask < (1 << $count); $mask++) {
            $subset = [];
            for ($bit = 0; $bit < $count; $bit++) {
                if ($mask & (1 << $bit)) {
                    $subset[] = $bit + 1;
                }
            }
            $subsets[] = $subset;
        }

        return $subsets;
    }

    /**
     * The ways a user could type $selection. A single-select question
     * accepts only one number, so its spellings vary in whitespace only.
     *
     * @param  list<int>  $selection  ascending
     * @return list<string>
     */
    private static function typedSpellings(array $selection, bool $allowMultiple): array
    {
        if (!$allowMultiple) {
            return [(string) $selection[0], ' '.$selection[0], $selection[0]."\n"];
        }

        $reversed = array_reverse($selection);

        return [
            implode(', ', $selection),
            implode(' ', $selection),
            implode(',', $reversed),
            implode(' ', $reversed).' '.$reversed[0],
        ];
    }

    /** @return list<array{number: int, text: string}> */
    private static function expectedSelected(array $selection): array
    {
        return array_map(fn (int $n) => ['number' => $n, 'text' => "Choice number {$n}"], $selection);
    }

    private function parse(string $raw, AgentQuestion $question): ParsedAnswer
    {
        return (new AnswerParser())->parse($raw, $question);
    }

    /** A pending question of this shape in a real row, owned by the conversation's user. */
    private function pendingRow(int $choiceCount, bool $allowMultiple): AgentQuestion
    {
        $this->conversation ??= Conversation::factory()->create(['is_processing' => false]);
        $id = (string) Str::uuid();
        $now = now();

        DB::table('agent_questions')->insert([
            'id' => $id,
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->conversation->user_id,
            'pending_conversation_id' => $this->conversation->id,
            'paused_message_id' => (string) Str::uuid(),
            'tool_call_id' => 'call_ask',
            'channel' => 'web',
            'resume_mode' => 'stream',
            'question' => 'Which ones?',
            'choices' => json_encode($choiceCount === 0 ? [] : self::choices($choiceCount)),
            'allow_multiple' => $allowMultiple,
            'state' => 'pending',
            'asked_at' => $now,
            'expires_at' => $now->copy()->addMinutes(1440),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return AgentQuestion::findOrFail($id);
    }

    /**
     * Record $answer through the service and return what it stored as the
     * answer — the same three fields a typed reply's ParsedAnswer carries.
     *
     * @return array{raw_reply: string, is_selection: bool, selected: list<array{number: int, text: string}>}
     */
    private function recordAndRead(AgentQuestion $question, InteractiveAnswer $answer, string $label): array
    {
        $outcome = app(InteractiveAnswerService::class)->record(
            $question->id,
            (string) $question->user_id,
            $answer,
            AnswerMethod::WebForm,
        );
        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status, "{$label}: recorded");

        $row = DB::table('agent_questions')->where('id', $question->id)->first();
        $selected = json_decode((string) $row->answer_selected, true);

        return [
            'raw_reply' => (string) $row->answer_raw,
            'is_selection' => $selected !== [],
            'selected' => $selected,
        ];
    }

    // ---------------------------------------------------------------
    // Selections
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('questionShapes')]
    public function every_selection_parses_exactly_as_each_typed_spelling_of_it(int $count, bool $allowMultiple): void
    {
        $question = $this->question($count, $allowMultiple);

        foreach (self::validSelections($count, $allowMultiple) as $selection) {
            $label = '['.implode(',', $selection).']';
            $interactive = $this->parse(InteractiveAnswer::selection($selection)->toReplyText(), $question);

            $this->assertTrue($interactive->isSelection, "{$label} is a selection");
            $this->assertSame(self::expectedSelected($selection), $interactive->selected, "{$label} selects exactly its choices, ascending");
            $this->assertSame(implode(', ', $selection), $interactive->rawReply, "{$label} reads as the canonical typed reply");

            foreach (self::typedSpellings($selection, $allowMultiple) as $typed) {
                $parsedTyped = $this->parse($typed, $question);

                $this->assertSame($interactive->isSelection, $parsedTyped->isSelection, "{$label} vs typed '{$typed}': same kind");
                $this->assertSame($interactive->selected, $parsedTyped->selected, "{$label} vs typed '{$typed}': same choices");
                $this->assertSame($typed, $parsedTyped->rawReply, 'only the raw reply differs');
            }
        }
    }

    #[Test]
    #[DataProvider('questionShapes')]
    public function every_selection_the_unordered_way_a_control_reports_it_parses_the_same(int $count, bool $allowMultiple): void
    {
        $question = $this->question($count, $allowMultiple);

        foreach (self::validSelections($count, $allowMultiple) as $selection) {
            $reported = array_reverse($selection);
            $asReported = $this->parse(InteractiveAnswer::selection($reported)->toReplyText(), $question);

            $this->assertSame(
                $this->parse(implode(', ', $selection), $question)->toArray(),
                $asReported->toArray(),
                'the order controls report a selection in does not change the answer',
            );
        }
    }

    /**
     * The parser-level property covers every shape; recording keeps to the
     * shapes small enough to record every selection of (at most 2^6 - 1 = 63
     * subsets per shape, each recorded in both orders).
     *
     * @return iterable<string, array{int, bool}>
     */
    public static function recordedShapes(): iterable
    {
        foreach (self::questionShapes() as $name => [$count, $allowMultiple]) {
            if ($count <= self::RECORDED_UP_TO_CHOICES) {
                yield $name => [$count, $allowMultiple];
            }
        }
    }

    #[Test]
    #[DataProvider('recordedShapes')]
    public function every_selection_recorded_by_the_service_matches_the_typed_reply(int $count, bool $allowMultiple): void
    {
        Event::fake([NewConversationMessageEvent::class]);
        $shape = $this->question($count, $allowMultiple);

        foreach (self::validSelections($count, $allowMultiple) as $selection) {
            // A control may report its checked boxes in either order; both
            // are recorded, each on its own pending question.
            foreach (['ascending' => $selection, 'reversed' => array_reverse($selection)] as $order => $reported) {
                $label = '['.implode(',', $reported).'] '.$order;
                $recorded = $this->recordAndRead(
                    $this->pendingRow($count, $allowMultiple),
                    InteractiveAnswer::selection($reported),
                    $label,
                );

                foreach (self::typedSpellings($selection, $allowMultiple) as $typed) {
                    $parsedTyped = $this->parse($typed, $shape)->toArray();
                    unset($parsedTyped['raw_reply']);
                    $comparable = $recorded;
                    unset($comparable['raw_reply']);

                    $this->assertSame($parsedTyped, $comparable, "{$label} recorded vs typed '{$typed}'");
                }
                $this->assertTrue($recorded['is_selection'], "{$label} is recorded as a selection");
                $this->assertSame(self::expectedSelected($selection), $recorded['selected']);
                $this->assertSame(implode(', ', $selection), $recorded['raw_reply'], 'stored as the canonical typed reply');
            }
        }
    }

    // ---------------------------------------------------------------
    // Free text
    // ---------------------------------------------------------------

    /** @return iterable<string, array{int, bool}> */
    public static function freeTextShapes(): iterable
    {
        yield 'no choices' => [0, false];
        yield '3 choices, single-select' => [3, false];
        yield '3 choices, multi-select' => [3, true];
        yield '10 choices, multi-select' => [10, true];
    }

    #[Test]
    #[DataProvider('freeTextShapes')]
    public function free_text_parses_exactly_as_the_same_text_typed(int $count, bool $allowMultiple): void
    {
        $question = $this->question($count, $allowMultiple);

        foreach (self::FREE_TEXTS as $text) {
            $interactive = $this->parse(InteractiveAnswer::freeText($text)->toReplyText(), $question);

            $this->assertSame($this->parse($text, $question)->toArray(), $interactive->toArray(), "free text '{$text}'");
            $this->assertSame($text, $interactive->rawReply, 'free text travels verbatim');
        }
    }

    #[Test]
    #[DataProvider('freeTextShapes')]
    public function free_text_recorded_by_the_service_matches_the_same_text_typed(int $count, bool $allowMultiple): void
    {
        Event::fake([NewConversationMessageEvent::class]);
        $shape = $this->question($count, $allowMultiple);

        foreach (self::FREE_TEXTS as $text) {
            $recorded = $this->recordAndRead($this->pendingRow($count, $allowMultiple), InteractiveAnswer::freeText($text), "free text '{$text}'");

            $this->assertSame($this->parse($text, $shape)->toArray(), $recorded, "free text '{$text}' recorded as typed");
        }
    }

    #[Test]
    public function free_text_that_names_a_choice_selects_it_exactly_as_typing_it_does(): void
    {
        $question = $this->question(3, false);

        // "2" typed into the free-text field is the number 2, as when typed as a reply.
        $this->assertSame(
            ['raw_reply' => '2', 'is_selection' => true, 'selected' => [['number' => 2, 'text' => 'Choice number 2']]],
            $this->parse(InteractiveAnswer::freeText('2')->toReplyText(), $question)->toArray(),
        );
        $this->assertSame(
            ['raw_reply' => '2.', 'is_selection' => false, 'selected' => []],
            $this->parse(InteractiveAnswer::freeText('2.')->toReplyText(), $question)->toArray(),
            '"2." is words, typed or not',
        );
    }
}
