<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_php_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('memory_limit', 8)->default('256M');
            $table->string('upload_max_filesize', 8)->default('64M');
            $table->string('post_max_size', 8)->default('64M');
            $table->unsignedSmallInteger('max_execution_time')->default(60);
            $table->boolean('display_errors')->default(false);
            $table->timestamps();
        });

        Schema::create('site_cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('expression', 64);
            $table->string('command', 500);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['site_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_cron_jobs');
        Schema::dropIfExists('site_php_settings');
    }
};
