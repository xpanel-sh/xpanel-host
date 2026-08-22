<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('ai_conversations', 'ai_conversation_scope_unique')) {
            Schema::table('ai_conversations', function (Blueprint $table): void {
                $table->dropUnique('ai_conversation_scope_unique');
            });
        }
        if (! Schema::hasIndex('ai_conversations', 'ai_conversation_scope_index')) {
            Schema::table('ai_conversations', function (Blueprint $table): void {
                $table->index(['user_id', 'ai_connection_id', 'scope_key'], 'ai_conversation_scope_index');
            });
        }
    }

    public function down(): void
    {
        // Several chats can now share one agent and scope, so restoring the old
        // unique constraint would destroy valid data or make rollback fail.
    }
};
