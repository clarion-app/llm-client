<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('llm_role_assignments')) {
            return;
        }

        Schema::create('llm_role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('role', 20);
            $table->uuid('user_id');
            $table->uuid('server_id');
            $table->string('model', 255);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['role', 'user_id']);
            $table->index('server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_role_assignments');
    }
};
