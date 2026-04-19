<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('public_chat_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('public_chat_messages', 'reply_to_id')) {
                $table->unsignedBigInteger('reply_to_id')->nullable()->after('user_id');
                $table->index('reply_to_id');
                $table->foreign('reply_to_id')
                    ->references('id')
                    ->on('public_chat_messages')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('public_chat_messages', function (Blueprint $table) {
            if (Schema::hasColumn('public_chat_messages', 'reply_to_id')) {
                $table->dropForeign(['reply_to_id']);
                $table->dropIndex(['reply_to_id']);
                $table->dropColumn('reply_to_id');
            }
        });
    }
};
