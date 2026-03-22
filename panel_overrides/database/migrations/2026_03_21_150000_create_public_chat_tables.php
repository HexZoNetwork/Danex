<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('public_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->text('body')->nullable();
            $table->string('media_type', 20)->default('text');
            $table->text('media_url')->nullable();
            $table->string('media_mime', 120)->nullable();
            $table->string('media_name', 255)->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('public_chat_message_reads', function (Blueprint $table) {
            $table->unsignedBigInteger('message_id');
            $table->unsignedInteger('user_id');
            $table->timestamp('read_at')->useCurrent()->onUpdate(null);

            $table->primary(['message_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
            $table->foreign('message_id')->references('id')->on('public_chat_messages')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_chat_message_reads');
        Schema::dropIfExists('public_chat_messages');
    }
};
