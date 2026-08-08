<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('agent_runs')) {
            return;
        }

        if (!Schema::hasColumn('agent_runs', 'is_streamed')) {
            Schema::table('agent_runs', function (Blueprint $table) {
                $table->boolean('is_streamed')->default(false)->after('created_at');
                $table->unsignedBigInteger('first_output_ms')->nullable()->after('is_streamed');
                $table->string('model', 128)->nullable()->after('first_output_ms');
                $table->string('agent_id', 255)->nullable()->after('model');
                $table->unsignedBigInteger('model_wait_ms')->nullable()->after('agent_id');
                $table->unsignedBigInteger('tool_exec_ms')->nullable()->after('model_wait_ms');
                $table->unsignedBigInteger('confirm_wait_ms')->nullable()->after('tool_exec_ms');
                $table->unsignedBigInteger('product_ms')->nullable()->after('confirm_wait_ms');

                $table->index(['model', 'started_at']);
                $table->index(['agent_id', 'started_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn([
                'is_streamed',
                'first_output_ms',
                'model',
                'agent_id',
                'model_wait_ms',
                'tool_exec_ms',
                'confirm_wait_ms',
                'product_ms',
            ]);
        });
    }
};
