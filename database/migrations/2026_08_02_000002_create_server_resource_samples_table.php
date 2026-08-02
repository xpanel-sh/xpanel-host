<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_resource_samples', function (Blueprint $table) {
            $table->id();
            $table->decimal('cpu_percent', 8, 2)->nullable();
            $table->unsignedBigInteger('memory_bytes')->default(0);
            $table->unsignedInteger('process_count')->default(0);
            $table->unsignedBigInteger('request_count')->default(0);
            $table->unsignedBigInteger('transfer_bytes')->default(0);
            $table->unsignedBigInteger('io_read_bytes')->default(0);
            $table->unsignedBigInteger('io_write_bytes')->default(0);
            $table->unsignedBigInteger('cpu_total_ticks')->default(0);
            $table->unsignedBigInteger('cpu_idle_ticks')->default(0);
            $table->unsignedBigInteger('io_read_total')->default(0);
            $table->unsignedBigInteger('io_write_total')->default(0);
            $table->timestamp('sampled_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_resource_samples');
    }
};
