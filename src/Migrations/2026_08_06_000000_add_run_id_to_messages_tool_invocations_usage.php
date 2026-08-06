<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('messages', 'run_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->uuid('run_id')->nullable()->after('tool_data');
                $table->index('run_id');
            });
        }

        if (!Schema::hasColumn('tool_invocation_records', 'run_id')) {
            Schema::table('tool_invocation_records', function (Blueprint $table) {
                $table->uuid('run_id')->nullable()->after('attempt_group_id');
                $table->index('run_id');
            });
        }

        if (!Schema::hasColumn('usage_records', 'run_id')) {
            Schema::table('usage_records', function (Blueprint $table) {
                $table->uuid('run_id')->nullable()->after('attempt_group_id');
                $table->index('run_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('run_id');
        });

        Schema::table('tool_invocation_records', function (Blueprint $table) {
            $table->dropColumn('run_id');
        });

        Schema::table('usage_records', function (Blueprint $table) {
            $table->dropColumn('run_id');
        });
    }
};
