<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The `ask_user` tool as the model is offered it.
 *
 * Every provider sends the definition AgentLoopService::buildToolsPayload()
 * produces, so this is the one place the schema is pinned. Besides the
 * question and its choices, the agent may say what happens if nobody
 * answers in time — the policy, the answer it would assume, whether the
 * question gates something consequential, and how long to wait — and none
 * of it names a channel, a chat, a conversation or how the question is
 * presented: the asking side is the same wherever the user is.
 */
class AskUserSchemaTest extends TestCase
{
    private const DESCRIPTION = 'Pause this task and ask the user a question. The task resumes when the user answers, or when the question expires or is withdrawn; the outcome is returned as this tool\'s result. Offer up to 10 choices when the answer is one of a known set; the user may always answer in their own words. Only one question can be pending in a conversation at a time. Decide per question what happens if the user does not answer in time: by default work stops until they answer; set if_unanswered to proceed_on_assumption and state the answer you will assume (assumed_choices or assumed_text) only when the question has a safe default. Mark questions that gate spending, deleting, sending or other real-world consequences as consequential — those can never proceed on an assumption.';

    private const PROPERTIES = [
        'question' => ['type' => 'string', 'description' => 'The question to ask, in plain text.'],
        'choices' => [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'maxItems' => 10,
            'description' => 'Optional choices, shown numbered from 1.',
        ],
        'allow_multiple' => ['type' => 'boolean', 'description' => 'Whether several choices may be selected. Requires choices.'],
        'if_unanswered' => [
            'type' => 'string',
            'enum' => ['proceed_on_assumption', 'stop'],
            'description' => 'What happens if no answer arrives in time. Default: stop (or proceed_on_assumption when an assumed answer is given).',
        ],
        'assumed_choices' => [
            'type' => 'array',
            'items' => ['type' => 'integer'],
            'description' => 'The choice number(s) to assume if unanswered. Exactly one unless allow_multiple.',
        ],
        'assumed_text' => [
            'type' => 'string',
            'description' => 'The free-text answer to assume if unanswered, read exactly as if the user had typed it. Use instead of assumed_choices.',
        ],
        'consequential' => [
            'type' => 'boolean',
            'description' => 'True when the answer gates an action with real-world consequences. A consequential question always stops if unanswered.',
        ],
        'wait_minutes' => [
            'type' => 'integer',
            'description' => 'How long to wait for an answer. Brought within the installation\'s minimum and maximum.',
        ],
    ];

    /** Words that would tie the asking side to where or how the question is shown. */
    private const CHANNEL_WORDS = ['channel', 'chat', 'conversation', 'presentation', 'telegram', 'web', 'delivery', 'deliver'];

    private function askUser(): array
    {
        $service = new AgentLoopService(
            app(McpToolRegistry::class),
            new McpToolExecutor(app(McpToolRegistry::class), null, fn ($user) => 'test-token'),
            new OperationCache(),
            app(ProviderRegistry::class),
        );

        $askUser = collect($service->buildToolsPayload())->firstWhere('function.name', 'ask_user');
        $this->assertNotNull($askUser, 'ask_user must be offered to the model');

        return $askUser;
    }

    #[Test]
    public function ask_user_is_offered_with_exactly_the_policy_schema(): void
    {
        $this->assertEquals([
            'type' => 'function',
            'function' => [
                'name' => 'ask_user',
                'description' => self::DESCRIPTION,
                'parameters' => [
                    'type' => 'object',
                    'properties' => self::PROPERTIES,
                    'required' => ['question'],
                ],
            ],
        ], $this->askUser());
    }

    #[Test]
    public function the_properties_are_exactly_the_eight_arguments_in_order(): void
    {
        $properties = (array) $this->askUser()['function']['parameters']['properties'];

        $this->assertSame(
            ['question', 'choices', 'allow_multiple', 'if_unanswered', 'assumed_choices', 'assumed_text', 'consequential', 'wait_minutes'],
            array_keys($properties),
        );
    }

    #[Test]
    public function only_the_question_is_required(): void
    {
        $this->assertSame(['question'], $this->askUser()['function']['parameters']['required']);
    }

    #[Test]
    public function the_policy_can_only_be_one_of_the_two_values(): void
    {
        $properties = (array) $this->askUser()['function']['parameters']['properties'];

        $this->assertSame(['proceed_on_assumption', 'stop'], $properties['if_unanswered']['enum'] ?? null);
        $this->assertSame('string', $properties['if_unanswered']['type'] ?? null);
    }

    #[Test]
    public function the_description_says_stopping_is_the_default_and_consequential_questions_never_assume(): void
    {
        $this->assertSame(self::DESCRIPTION, $this->askUser()['function']['description']);
    }

    #[Test]
    public function nothing_in_the_new_arguments_names_a_channel_or_how_the_question_is_shown(): void
    {
        $properties = (array) $this->askUser()['function']['parameters']['properties'];
        $this->assertArrayHasKey('if_unanswered', $properties, 'precondition: the policy arguments are offered');

        foreach ($properties as $name => $definition) {
            $text = strtolower($name.' '.($definition['description'] ?? ''));
            foreach (self::CHANNEL_WORDS as $word) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b'.preg_quote($word, '/').'/',
                    $text,
                    "the {$name} argument must not mention \"{$word}\"",
                );
            }
        }
    }
}
