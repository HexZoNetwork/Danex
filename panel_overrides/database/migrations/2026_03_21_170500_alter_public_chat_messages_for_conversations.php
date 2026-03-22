<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('public_chat_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('public_chat_messages', 'conversation_id')) {
                $table->unsignedBigInteger('conversation_id')->nullable()->after('id');
                $table->index(['conversation_id', 'id']);
                $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
            }
            if (!Schema::hasColumn('public_chat_messages', 'mention_usernames')) {
                $table->json('mention_usernames')->nullable()->after('reply_to_id');
            }
            if (!Schema::hasColumn('public_chat_messages', 'poll_question')) {
                $table->string('poll_question', 255)->nullable()->after('media_name');
            }
            if (!Schema::hasColumn('public_chat_messages', 'poll_options')) {
                $table->json('poll_options')->nullable()->after('poll_question');
            }
        });

        DB::table('public_chat_messages')->whereNull('conversation_id')->update(['conversation_id' => 1]);
    }

    public function down(): void
    {
        Schema::table('public_chat_messages', function (Blueprint $table) {
            if (Schema::hasColumn('public_chat_messages', 'poll_options')) {
                $table->dropColumn('poll_options');
            }
            if (Schema::hasColumn('public_chat_messages', 'poll_question')) {
                $table->dropColumn('poll_question');
            }
            if (Schema::hasColumn('public_chat_messages', 'mention_usernames')) {
                $table->dropColumn('mention_usernames');
            }
            if (Schema::hasColumn('public_chat_messages', 'conversation_id')) {
                $table->dropForeign(['conversation_id']);
                $table->dropIndex(['conversation_id', 'id']);
                $table->dropColumn('conversation_id');
            }
        });
    }
};
