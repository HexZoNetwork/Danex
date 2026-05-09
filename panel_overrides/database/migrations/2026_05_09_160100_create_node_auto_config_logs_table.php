<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('node_auto_config_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id');
            $table->string('level', 16)->default('info')->index();
            $table->string('step', 64)->index();
            $table->string('event', 64)->nullable()->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at_override')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'id']);
            $table->foreign('run_id')->references('id')->on('node_auto_config_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_auto_config_logs');
    }
};
