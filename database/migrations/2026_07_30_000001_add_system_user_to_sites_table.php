<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('system_user', 32)->nullable()->unique()->after('document_root');
        });
        DB::table('sites')->orderBy('id')->eachById(function ($site): void {
            DB::table('sites')->where('id', $site->id)->update([
                'system_user' => 'xps'.base_convert((string) $site->id, 10, 36).substr(hash('sha256', $site->domain), 0, 8),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('system_user'));
    }
};
