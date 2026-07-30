<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_server_engines', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('status')->default('available');
            $table->string('version')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });

        DB::table('web_server_engines')->insert([
            ['slug' => 'nginx', 'label' => 'Nginx', 'status' => 'installed', 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'apache', 'label' => 'Apache', 'status' => 'available', 'installed_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'openlitespeed', 'label' => 'OpenLiteSpeed', 'status' => 'available', 'installed_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        if (Schema::hasTable('sites') && DB::table('sites')->where('web_server', 'apache')->exists()) {
            DB::table('web_server_engines')->where('slug', 'apache')->update([
                'status' => 'installed', 'installed_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('web_server_engines');
    }
};
