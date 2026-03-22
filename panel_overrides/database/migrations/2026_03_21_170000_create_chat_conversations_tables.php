<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // global|private|group
            $table->string('name', 191)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('chat_conversation_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedInteger('user_id');
            $table->timestamp('joined_at')->useCurrent()->onUpdate(null);

            $table->primary(['conversation_id', 'user_id']);
            $table->index(['user_id', 'joined_at']);
            $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('chat_poll_votes', function (Blueprint $table) {
            $table->unsignedBigInteger('message_id');
            $table->unsignedInteger('user_id');
            $table->unsignedSmallInteger('option_index');
            $table->timestamp('created_at')->useCurrent()->onUpdate(null);

            $table->primary(['message_id', 'user_id']);
            $table->index(['message_id', 'option_index']);
            $table->foreign('message_id')->references('id')->on('public_chat_messages')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::table('chat_conversations')->insert([
            'id' => 1,
            'type' => 'global',
            'name' => 'Global',
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_poll_votes');
        Schema::dropIfExists('chat_conversation_participants');
        Schema::dropIfExists('chat_conversations');
    }
};
