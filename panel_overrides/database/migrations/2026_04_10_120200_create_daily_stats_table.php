<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('daily_stats')) {
            return;
        }

        Schema::create('daily_stats', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedInteger('total_suspend')->default(0);
            $table->unsignedInteger('total_files_deleted')->default(0);
            $table->unsignedInteger('total_process_killed')->default(0);
            $table->unsignedInteger('unique_users')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
