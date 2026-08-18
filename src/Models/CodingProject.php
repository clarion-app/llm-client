<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;

/**
 * A registered project — a canonicalized root directory on the host plus an
 * explicit, user-set test command — that a conversation can be pointed at
 * (112-coding-agent, Foundational, data-model.md §1). Mirrors `Server`'s own
 * bridging shape exactly: persistent, user-owned configuration, not
 * ephemeral/frequently-changing execution data.
 *
 * `root_path` is always the `realpath()`-resolved absolute directory,
 * stored once at registration (CodingProjectController::store()) — never
 * the raw user input. `test_command` is opaque, user-authored, and never
 * parsed, validated against a framework, or altered by this feature.
 */
class CodingProject extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $fillable = ['user_id', 'name', 'root_path', 'test_command', 'confirmation_relaxed'];
    protected $table = 'coding_projects';
}
