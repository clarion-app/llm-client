<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_search_index', function (Blueprint $table) {
            $table->id();
            $table->string('operation_id')->unique();
            $table->string('package_name');
            $table->string('summary', 500)->nullable();
            $table->string('method', 10);
            $table->string('path', 1000);
            $table->text('searchable_text');
            $table->json('param_schema')->nullable();
            $table->timestamps();

            // Fulltext index not supported on SQLite (used for testing)
            $driver = config('database.default', 'sqlite');
            $connection = DB::connection($driver);
            if ($connection->getDriverName() !== 'sqlite') {
                $table->fullText(['searchable_text']);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_search_index');
    }
};
