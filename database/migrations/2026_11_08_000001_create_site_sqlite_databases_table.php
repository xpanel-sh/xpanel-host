<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_sqlite_databases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('relative_path', 255);
            $table->timestamps();
            $table->unique(['site_id', 'relative_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_sqlite_databases');
    }
};
