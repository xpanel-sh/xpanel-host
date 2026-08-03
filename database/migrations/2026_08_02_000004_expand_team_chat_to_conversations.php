<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->string('name')->nullable();
            $table->string('direct_key')->nullable()->unique();
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('team_conversation_user', function (Blueprint $table) {
            $table->foreignId('team_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('joined_at')->useCurrent();
            $table->primary(['team_conversation_id', 'user_id']);
        });

        Schema::table('team_messages', function (Blueprint $table) {
            $table->foreignId('team_conversation_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->index(['team_conversation_id', 'created_at']);
        });

        $now = now();
        $conversationId = DB::table('team_conversations')->insertGetId([
            'type' => 'group',
            'name' => 'Equipo',
            'is_default' => true,
            'created_by' => DB::table('users')->orderBy('id')->value('id'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('team_messages')->update(['team_conversation_id' => $conversationId]);
        $reads = DB::table('team_chat_reads')->pluck('last_read_at', 'user_id');
        DB::table('users')->orderBy('id')->eachById(function ($user) use ($conversationId, $reads, $now): void {
            $readAt = $reads->get($user->id);
            DB::table('team_conversation_user')->insert([
                'team_conversation_id' => $conversationId,
                'user_id' => $user->id,
                'last_read_at' => $readAt,
                'last_read_message_id' => $readAt === null ? null : DB::table('team_messages')->where('created_at', '<=', $readAt)->max('id'),
                'joined_at' => $now,
            ]);
        });
        Schema::dropIfExists('team_chat_reads');
    }

    public function down(): void
    {
        Schema::create('team_chat_reads', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
        });
        $default = DB::table('team_conversations')->where('is_default', true)->value('id');
        if ($default !== null) {
            DB::table('team_conversation_user')->where('team_conversation_id', $default)->eachById(function ($member): void {
                DB::table('team_chat_reads')->insert([
                    'user_id' => $member->user_id,
                    'last_read_at' => $member->last_read_at,
                ]);
            }, column: 'user_id');
        }
        Schema::table('team_messages', function (Blueprint $table) {
            $table->dropIndex(['team_conversation_id', 'created_at']);
            $table->dropConstrainedForeignId('team_conversation_id');
        });
        Schema::dropIfExists('team_conversation_user');
        Schema::dropIfExists('team_conversations');
    }
};
