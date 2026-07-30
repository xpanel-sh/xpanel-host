<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_ip_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('action', 16);
            $table->string('address', 64);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['site_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_ip_rules');
    }
};
