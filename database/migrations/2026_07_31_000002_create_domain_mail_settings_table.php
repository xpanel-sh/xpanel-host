<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_mail_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('outbound_mode', 16)->default('shared');
            $table->foreignId('server_ip_address_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_mail_settings');
    }
};
