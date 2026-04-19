<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('user_violations')) {
            return;
        }

        Schema::create('user_violations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('username', 191)->nullable();
            $table->unsignedBigInteger('server_id')->index();
            $table->string('server_uuid', 191)->nullable()->index();
            $table->string('server_name', 191)->nullable();
            $table->string('violation_type', 100)->index();
            $table->text('details')->nullable();
            $table->string('file_name', 512)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->decimal('disk_usage_gb', 10, 2)->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->string('action_taken', 64)->index();
            $table->unsignedTinyInteger('severity')->default(1)->index();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['violation_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_violations');
    }
};
