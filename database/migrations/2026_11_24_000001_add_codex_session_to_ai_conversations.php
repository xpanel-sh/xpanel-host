<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_connections', fn (Blueprint $table) => $table->text('api_key')->nullable()->change());
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->string('codex_thread_id', 64)->nullable();
            $table->string('codex_job_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', fn (Blueprint $table) => $table->dropColumn(['codex_thread_id', 'codex_job_id']));
    }
};
