<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tool_invocation_records') && !Schema::hasColumn('tool_invocation_records', 'agent_id')) {
            Schema::table('tool_invocation_records', function (Blueprint $table) {
                $table->string('agent_id', 255)->nullable()->after('run_id');
                $table->index('agent_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('tool_invocation_records', function (Blueprint $table) {
            $table->dropColumn('agent_id');
        });
    }
};
