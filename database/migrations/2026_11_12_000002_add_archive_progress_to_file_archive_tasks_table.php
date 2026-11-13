<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_archive_tasks', function (Blueprint $table): void {
            $table->unsignedTinyInteger('progress_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('file_archive_tasks', function (Blueprint $table): void {
            $table->dropColumn('progress_percent');
        });
    }
};
