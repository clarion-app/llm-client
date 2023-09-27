<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('server_group_id')->nullable();
            $table->foreign('server_group_id')->references('id')->on('server_groups')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('user_id')->nullable();
            $table->string('title');
            $table->string('model');
            $table->string('character');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
