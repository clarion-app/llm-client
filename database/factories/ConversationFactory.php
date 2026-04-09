<?php

namespace ClarionApp\LlmClient\Database\Factories;

use ClarionApp\LlmClient\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'server_id' => (string) Str::uuid(),
            'title' => null,
            'model' => 'gpt-4',
            'character' => 'Clarion',
            'user_id' => (string) Str::uuid(),
            'is_processing' => false,
        ];
    }
}
