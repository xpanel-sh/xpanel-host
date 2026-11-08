<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_databases', function (Blueprint $table) {
            $table->string('engine', 24)->default('mariadb')->after('site_id');
            $table->index(['engine', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('site_databases', function (Blueprint $table) {
            $table->dropIndex(['engine', 'status']);
            $table->dropColumn('engine');
        });
    }
};
