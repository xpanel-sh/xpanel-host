<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('tenancy_mode', 16)->default('none')->after('type');
            $table->boolean('wildcard_domain')->default(false)->after('tenancy_mode');
            $table->string('wildcard_ssl_status', 16)->default('disabled')->after('wildcard_domain');
            $table->string('node_version', 8)->nullable()->after('php_version');
            $table->unsignedSmallInteger('runtime_port')->nullable()->after('node_version');
            $table->string('node_start_command')->nullable()->after('runtime_port');
            $table->unique('runtime_port');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn([
                'tenancy_mode', 'wildcard_domain', 'wildcard_ssl_status',
                'node_version', 'runtime_port', 'node_start_command',
            ]);
        });
    }
};
