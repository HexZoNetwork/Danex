<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            if (!Schema::hasColumn('chat_conversations', 'group_username')) {
                $table->string('group_username', 64)->nullable();
                $table->unique('group_username');
            }
            if (!Schema::hasColumn('chat_conversations', 'group_code')) {
                $table->string('group_code', 64)->nullable();
                $table->unique('group_code');
            }
            if (!Schema::hasColumn('chat_conversations', 'private_user_low')) {
                $table->unsignedInteger('private_user_low')->nullable();
            }
            if (!Schema::hasColumn('chat_conversations', 'private_user_high')) {
                $table->unsignedInteger('private_user_high')->nullable();
            }
            $table->index(['private_user_low', 'private_user_high'], 'chat_conversations_private_users_idx');
            $table->unique(['private_user_low', 'private_user_high'], 'chat_conversations_private_users_unique');
        });

        Schema::table('chat_conversation_participants', function (Blueprint $table) {
            if (!Schema::hasColumn('chat_conversation_participants', 'role')) {
                $table->string('role', 16)->default('member');
                $table->index(['conversation_id', 'role'], 'chat_conversation_participants_role_idx');
            }
        });

        Schema::create('chat_conversation_bans', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('banned_by')->nullable();
            $table->string('reason', 191)->nullable();
            $table->timestamp('created_at')->useCurrent()->onUpdate(null);

            $table->primary(['conversation_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
            $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('banned_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('public_chat_message_reactions', function (Blueprint $table) {
            $table->unsignedBigInteger('message_id');
            $table->unsignedInteger('user_id');
            $table->string('emoji', 16);
            $table->timestamp('created_at')->useCurrent()->onUpdate(null);

            $table->primary(['message_id', 'user_id', 'emoji']);
            $table->index(['message_id', 'emoji']);
            $table->foreign('message_id')->references('id')->on('public_chat_messages')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::table('chat_conversation_participants as p')
            ->join('chat_conversations as c', 'c.id', '=', 'p.conversation_id')
            ->whereNotNull('c.created_by')
            ->whereColumn('p.user_id', 'c.created_by')
            ->update(['p.role' => 'owner']);

        $private = DB::table('chat_conversations')
            ->where('type', 'private')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $seen = [];
        foreach ($private as $conversationId) {
            $ids = DB::table('chat_conversation_participants')
                ->where('conversation_id', $conversationId)
                ->orderBy('user_id')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if (count($ids) !== 2) {
                continue;
            }

            $pairKey = $ids[0] . ':' . $ids[1];
            if (isset($seen[$pairKey])) {
                continue;
            }

            $seen[$pairKey] = true;
            DB::table('chat_conversations')
                ->where('id', $conversationId)
                ->update([
                    'private_user_low' => $ids[0],
                    'private_user_high' => $ids[1],
                ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('public_chat_message_reactions');
        Schema::dropIfExists('chat_conversation_bans');

        Schema::table('chat_conversation_participants', function (Blueprint $table) {
            if (Schema::hasColumn('chat_conversation_participants', 'role')) {
                $table->dropIndex('chat_conversation_participants_role_idx');
                $table->dropColumn('role');
            }
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            if (Schema::hasColumn('chat_conversations', 'private_user_low')) {
                $table->dropUnique('chat_conversations_private_users_unique');
                $table->dropIndex('chat_conversations_private_users_idx');
                $table->dropColumn('private_user_low');
            }
            if (Schema::hasColumn('chat_conversations', 'private_user_high')) {
                $table->dropColumn('private_user_high');
            }
            if (Schema::hasColumn('chat_conversations', 'group_username')) {
                $table->dropUnique(['group_username']);
                $table->dropColumn('group_username');
            }
            if (Schema::hasColumn('chat_conversations', 'group_code')) {
                $table->dropUnique(['group_code']);
                $table->dropColumn('group_code');
            }
        });
    }
};

