<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('coding_command_executions', 'language')) {
            Schema::table('coding_command_executions', function (Blueprint $table) {
                $table->string('language')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('coding_command_executions', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
