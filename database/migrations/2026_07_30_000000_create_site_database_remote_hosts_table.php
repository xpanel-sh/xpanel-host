<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_database_remote_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_database_id')->constrained()->cascadeOnDelete();
            $table->string('address', 45);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['site_database_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_database_remote_hosts');
    }
};
