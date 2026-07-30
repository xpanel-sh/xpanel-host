<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_migrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_database_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_backup_id')->nullable()->constrained()->nullOnDelete();
            $table->string('application', 40);
            $table->string('status', 20)->default('running');
            $table->string('source_url', 2048)->nullable();
            $table->unsignedBigInteger('files_count')->default(0);
            $table->unsignedBigInteger('bytes_imported')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_migrations');
    }
};
