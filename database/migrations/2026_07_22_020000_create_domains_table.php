<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->enum('type', ['primary', 'alias', 'subdomain'])->default('primary');
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dns_status')->default('pending');
            $table->string('ssl_status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
