<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tool_invocation_records')) {
            return;
        }

        Schema::create('tool_invocation_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('user_id');
            $table->uuid('attempt_group_id');
            $table->string('tool_name', 256);
            $table->enum('outcome', ['success', 'failure']);
            $table->string('failure_category')->nullable();
            $table->json('co_member_tags')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('conversation_id');
            $table->index('user_id');
            $table->index('attempt_group_id');
            $table->index(['tool_name', 'outcome']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_invocation_records');
    }
};
