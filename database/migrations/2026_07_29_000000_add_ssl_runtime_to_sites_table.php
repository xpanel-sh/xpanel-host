<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('ssl_status')->default('disabled')->after('status');
            $table->timestamp('ssl_expires_at')->nullable()->after('ssl_status');
            $table->string('ssl_issuer')->nullable()->after('ssl_expires_at');
            $table->boolean('https_redirect')->default(true)->after('ssl_issuer');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['ssl_status', 'ssl_expires_at', 'ssl_issuer', 'https_redirect']);
        });
    }
};
