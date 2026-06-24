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
            'id' => (string) Str::uuid(),
            'server_id' => null,
            'title' => null,
            'model' => 'gpt-4',
            'character' => 'Clarion',
            'user_id' => (string) Str::uuid(),
            'is_processing' => false,
        ];
    }
}
