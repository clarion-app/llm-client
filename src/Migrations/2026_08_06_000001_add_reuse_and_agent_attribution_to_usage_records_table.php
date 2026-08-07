<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('usage_records')) {
            return;
        }

        if (!Schema::hasColumn('usage_records', 'reused_input_tokens')) {
            Schema::table('usage_records', function (Blueprint $table) {
                $table->integer('reused_input_tokens')->nullable()->after('total_tokens');
                $table->boolean('reused_input_estimated')->default(false)->after('reused_input_tokens');
                $table->boolean('reused_input_adjusted')->default(false)->after('reused_input_estimated');
            });
        }

        if (!Schema::hasColumn('usage_records', 'agent_id')) {
            Schema::table('usage_records', function (Blueprint $table) {
                $table->string('agent_id', 255)->nullable()->after('attempt_group_id');
                $table->index('agent_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table) {
            $table->dropIndex(['agent_id']);
            $table->dropColumn([
                'reused_input_tokens',
                'reused_input_estimated',
                'reused_input_adjusted',
                'agent_id',
            ]);
        });
    }
};
