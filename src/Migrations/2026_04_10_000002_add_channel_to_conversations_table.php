<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('conversations', 'channel')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->string('channel', 50)->nullable()->after('is_processing');
                $table->index(['user_id', 'channel', 'updated_at'], 'conversations_user_channel_updated_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_user_channel_updated_index');
            $table->dropColumn('channel');
        });
    }
};
