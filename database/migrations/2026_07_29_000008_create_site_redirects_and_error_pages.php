<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('type', 16)->default('primary')->after('domain');
        });

        Schema::create('site_redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('source_path', 255);
            $table->string('match_type', 16)->default('exact');
            $table->string('target_url', 2048);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['site_id', 'source_path', 'match_type']);
        });

        Schema::create('site_error_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('status_code');
            $table->longText('content');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['site_id', 'status_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_error_pages');
        Schema::dropIfExists('site_redirects');
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
