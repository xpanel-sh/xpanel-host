<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_git_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('repository_url', 2048);
            $table->string('branch', 128)->default('main');
            $table->string('last_commit', 64)->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('protected_directories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('username', 64);
            $table->string('password_hash');
            $table->string('realm', 128)->default('Área protegida');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['site_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protected_directories');
        Schema::dropIfExists('site_git_repositories');
    }
};
