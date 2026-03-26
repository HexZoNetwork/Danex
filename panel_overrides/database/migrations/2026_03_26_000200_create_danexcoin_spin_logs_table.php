<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('danexcoin_spin_logs')) {
            Schema::create('danexcoin_spin_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id');
                $table->decimal('bet', 16, 2);
                $table->string('reel_1', 32);
                $table->string('reel_2', 32);
                $table->string('reel_3', 32);
                $table->decimal('multiplier', 6, 2)->default(0);
                $table->decimal('payout', 16, 2)->default(0);
                $table->decimal('balance_before', 16, 2);
                $table->decimal('balance_after', 16, 2);
                $table->boolean('is_jackpot')->default(false);
                $table->timestamp('created_at')->useCurrent();

                $table->index(['user_id', 'id'], 'danexcoin_spin_logs_user_id_idx');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('danexcoin_spin_logs');
    }
};
