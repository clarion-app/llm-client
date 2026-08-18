<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('coding_projects', 'confirmation_relaxed')) {
            Schema::table('coding_projects', function (Blueprint $table) {
                $table->boolean('confirmation_relaxed')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::table('coding_projects', function (Blueprint $table) {
            $table->dropColumn('confirmation_relaxed');
        });
    }
};
