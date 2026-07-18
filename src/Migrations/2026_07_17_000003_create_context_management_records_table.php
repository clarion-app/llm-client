<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('context_management_records')) {
            return;
        }

        Schema::create('context_management_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('user_id');
            $table->uuid('attempt_group_id')->nullable();
            $table->enum('mechanism', ['trim', 'smart_trim', 'condense', 'none']);
            $table->integer('history_budget')->nullable();
            $table->integer('context_capacity')->nullable();
            $table->integer('tokens_before')->default(0);
            $table->integer('tokens_after')->default(0);
            $table->integer('tokens_saved')->default(0);
            $table->string('model', 128)->nullable();
            $table->string('provider_type', 32)->nullable();
            $table->string('error', 256)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('conversation_id');
            $table->index(['user_id', 'created_at']);
            $table->index('attempt_group_id');
            $table->index(['mechanism', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('context_management_records');
    }
};
