<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_database_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->string('status', 20)->default('installing');
            $table->string('version', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_applications');
    }
};
