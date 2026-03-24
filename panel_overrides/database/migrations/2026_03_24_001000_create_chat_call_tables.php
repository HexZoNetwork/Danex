<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('chat_call_sessions')) {
            Schema::create('chat_call_sessions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedInteger('started_by');
                $table->timestamp('ended_at')->nullable();
                $table->timestamps();

                $table->index(['conversation_id', 'ended_at'], 'chat_call_sessions_conv_active_idx');
                $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
                $table->foreign('started_by')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('chat_call_participants')) {
            Schema::create('chat_call_participants', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('call_session_id');
                $table->unsignedInteger('user_id');
                $table->boolean('mic_muted')->default(false);
                $table->unsignedTinyInteger('speaking_level')->default(0);
                $table->timestamp('joined_at')->nullable();
                $table->timestamp('left_at')->nullable();
                $table->timestamps();

                $table->unique(['call_session_id', 'user_id'], 'chat_call_participants_unique');
                $table->index(['call_session_id', 'left_at'], 'chat_call_participants_active_idx');
                $table->foreign('call_session_id')->references('id')->on('chat_call_sessions')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('chat_call_signals')) {
            Schema::create('chat_call_signals', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('call_session_id');
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedInteger('from_user_id')->nullable();
                $table->unsignedInteger('to_user_id')->nullable();
                $table->string('type', 24);
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['conversation_id', 'id'], 'chat_call_signals_conv_id_idx');
                $table->index(['call_session_id', 'id'], 'chat_call_signals_call_id_idx');
                $table->index(['to_user_id', 'id'], 'chat_call_signals_to_id_idx');
                $table->foreign('call_session_id')->references('id')->on('chat_call_sessions')->cascadeOnDelete();
                $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
                $table->foreign('from_user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('to_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_call_signals');
        Schema::dropIfExists('chat_call_participants');
        Schema::dropIfExists('chat_call_sessions');
    }
};
