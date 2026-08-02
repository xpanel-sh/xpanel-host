<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_resource_samples', function (Blueprint $table) {
            $table->unsignedBigInteger('filesystem_bytes')->default(0)->after('disk_bytes');
            $table->unsignedBigInteger('filesystem_inodes')->default(0)->after('inode_count');
        });
    }

    public function down(): void
    {
        Schema::table('site_resource_samples', function (Blueprint $table) {
            $table->dropColumn(['filesystem_bytes', 'filesystem_inodes']);
        });
    }
};
