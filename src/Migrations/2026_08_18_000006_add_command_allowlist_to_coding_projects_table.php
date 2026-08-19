<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('coding_projects', 'command_allowlist')) {
            Schema::table('coding_projects', function (Blueprint $table) {
                $table->json('command_allowlist')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('coding_projects', function (Blueprint $table) {
            $table->dropColumn('command_allowlist');
        });
    }
};
