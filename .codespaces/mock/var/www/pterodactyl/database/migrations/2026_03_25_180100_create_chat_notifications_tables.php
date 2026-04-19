<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('chat_notifications')) {
            Schema::create('chat_notifications', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id');
                $table->unsignedBigInteger('conversation_id')->nullable();
                $table->unsignedInteger('from_user_id')->nullable();
                $table->string('source_type', 24);
                $table->string('title', 191);
                $table->text('body')->nullable();
                $table->string('avatar_url', 2048)->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['user_id', 'id'], 'chat_notifications_user_id_idx');
                $table->index(['conversation_id', 'id'], 'chat_notifications_conv_id_idx');
                $table->index(['source_type', 'id'], 'chat_notifications_source_id_idx');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('conversation_id')->references('id')->on('chat_conversations')->nullOnDelete();
                $table->foreign('from_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('chat_notification_reads')) {
            Schema::create('chat_notification_reads', function (Blueprint $table) {
                $table->unsignedBigInteger('notification_id');
                $table->unsignedInteger('user_id');
                $table->timestamp('read_at')->nullable();

                $table->primary(['notification_id', 'user_id'], 'chat_notification_reads_pk');
                $table->index(['user_id', 'notification_id'], 'chat_notification_reads_user_idx');
                $table->foreign('notification_id')->references('id')->on('chat_notifications')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('chat_notification_mutes')) {
            Schema::create('chat_notification_mutes', function (Blueprint $table) {
                $table->unsignedInteger('user_id');
                $table->unsignedBigInteger('conversation_id');
                $table->timestamp('muted_until')->nullable();
                $table->timestamps();

                $table->primary(['user_id', 'conversation_id'], 'chat_notification_mutes_pk');
                $table->index(['conversation_id', 'muted_until'], 'chat_notification_mutes_conv_idx');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_notification_mutes');
        Schema::dropIfExists('chat_notification_reads');
        Schema::dropIfExists('chat_notifications');
    }
};
