<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mcp_sessions') || !Schema::hasColumn('mcp_sessions', 'user_id')) {
            return;
        }

        Schema::table('mcp_sessions', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('mcp_sessions') || !Schema::hasColumn('mcp_sessions', 'user_id')) {
            return;
        }

        Schema::table('mcp_sessions', function (Blueprint $table) {
            $table->uuid('user_id')->nullable(false)->change();
        });
    }
};
