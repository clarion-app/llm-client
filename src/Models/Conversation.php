<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MetaverseSystems\EloquentMultiChainBridge\EloquentMultiChainBridge;

class Conversation extends Model
{
    use HasFactory, EloquentMultiChainBridge;
}
