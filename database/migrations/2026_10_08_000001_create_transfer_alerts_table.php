<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('month', 7);
            $table->unsignedTinyInteger('threshold');
            $table->unsignedBigInteger('bytes');
            $table->timestamps();
            $table->unique(['month', 'threshold']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_alerts');
    }
};
