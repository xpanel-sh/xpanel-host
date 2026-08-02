<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_resource_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('disk_bytes')->default(0);
            $table->unsignedBigInteger('inode_count')->default(0);
            $table->unsignedBigInteger('database_bytes')->default(0);
            $table->decimal('cpu_percent', 8, 2)->nullable();
            $table->unsignedBigInteger('memory_bytes')->default(0);
            $table->unsignedInteger('process_count')->default(0);
            $table->unsignedBigInteger('request_count')->default(0);
            $table->unsignedBigInteger('transfer_bytes')->default(0);
            $table->unsignedBigInteger('io_read_bytes')->default(0);
            $table->unsignedBigInteger('io_write_bytes')->default(0);
            $table->unsignedBigInteger('io_read_total')->default(0);
            $table->unsignedBigInteger('io_write_total')->default(0);
            $table->timestamp('sampled_at');
            $table->timestamps();
            $table->index(['site_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_resource_samples');
    }
};
