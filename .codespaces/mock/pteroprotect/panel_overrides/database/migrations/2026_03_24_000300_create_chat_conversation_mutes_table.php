<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('chat_conversation_mutes')) {
            Schema::create('chat_conversation_mutes', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('conversation_id');
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('muted_by');
                $table->timestamp('expires_at')->nullable();
                $table->string('reason', 191)->nullable();
                $table->timestamps();

                $table->unique(['conversation_id', 'user_id']);
                $table->index(['conversation_id', 'expires_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversation_mutes');
    }
};
