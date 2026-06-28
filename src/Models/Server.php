<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\Contracts\ProviderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;

class Server extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $fillable = ['name', 'server_url', 'token', 'provider_type'];
    protected $hidden = ['token'];
    protected $casts = [
        'token' => 'encrypted',
        'provider_type' => ProviderType::class,
    ];
    protected $table = 'llm_servers';

    /**
     * Get the provider type as a ProviderType enum.
     * Defaults to OpenAI for legacy records with null provider_type.
     */
    public function getProviderTypeAttribute(?string $value): ProviderType
    {
        if ($value === null) {
            return ProviderType::OpenAI;
        }

        return ProviderType::tryFrom($value) ?? ProviderType::OpenAI;
    }
}
