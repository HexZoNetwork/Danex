<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('chat_user_presence')) {
            return;
        }

        Schema::create('chat_user_presence', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_user_presence');
    }
};
