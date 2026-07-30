<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_access_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('sftp_enabled')->default(false);
            $table->boolean('ftp_enabled')->default(false);
            $table->boolean('ssh_enabled')->default(false);
            $table->timestamp('password_rotated_at')->nullable();
            $table->timestamps();
        });
        Schema::create('site_ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('public_key');
            $table->string('fingerprint', 100)->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_ssh_keys');
        Schema::dropIfExists('site_access_settings');
    }
};
