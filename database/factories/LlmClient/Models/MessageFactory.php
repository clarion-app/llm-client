<?php

namespace Database\Factories\LlmClient\Models;

use ClarionApp\LlmClient\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'role' => 'user',
            'user' => 'Test User',
            'content' => 'Test message content',
            'responseTime' => 0,
        ];
    }
}
