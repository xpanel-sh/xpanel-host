<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->string('ptr_hostname');
            $table->string('label')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_ip_addresses');
    }
};
