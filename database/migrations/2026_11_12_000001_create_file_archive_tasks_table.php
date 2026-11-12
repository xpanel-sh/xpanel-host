<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_archive_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('target_site_id')->nullable()->constrained('sites')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('sources');
            $table->string('target', 2048);
            $table->string('status', 24)->index();
            $table->unsignedBigInteger('entries')->default(0);
            $table->unsignedInteger('worker_pid')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_archive_tasks');
    }
};
