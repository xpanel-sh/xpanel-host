<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('php_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('php_version', 8);
            $table->json('extensions');
            $table->timestamps();
            $table->unique(['php_version', 'name']);
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->foreignId('php_profile_id')->nullable()->after('php_version')->constrained('php_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropConstrainedForeignId('php_profile_id'));
        Schema::dropIfExists('php_profiles');
    }
};
