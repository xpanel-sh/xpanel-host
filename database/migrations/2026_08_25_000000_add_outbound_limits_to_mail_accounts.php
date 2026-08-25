<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table): void {
            $table->unsignedInteger('hourly_send_limit')->default(100)->after('quota_mb');
            $table->unsignedInteger('daily_send_limit')->default(500)->after('hourly_send_limit');
        });
    }

    public function down(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table): void {
            $table->dropColumn(['hourly_send_limit', 'daily_send_limit']);
        });
    }
};
