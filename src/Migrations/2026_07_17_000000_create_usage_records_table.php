<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('usage_records')) {
            return;
        }

        Schema::create('usage_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('user_id');
            $table->uuid('attempt_group_id');
            $table->integer('input_tokens')->nullable()->default(0);
            $table->integer('output_tokens')->nullable()->default(0);
            $table->integer('total_tokens')->nullable()->default(0);
            $table->boolean('input_estimated')->default(false);
            $table->boolean('output_estimated')->default(false);
            $table->string('model', 128)->nullable();
            $table->string('provider_type', 32)->nullable();
            $table->json('co_member_tags')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('conversation_id');
            $table->index('user_id');
            $table->index('attempt_group_id');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
