<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_user_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->uuid('server_id')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('server_id')
                  ->references('id')
                  ->on('llm_servers')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_user_settings');
    }
};
