<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('illegal_files')) {
            return;
        }

        Schema::create('illegal_files', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('file_hash', 191)->unique();
            $table->string('file_name', 512)->nullable();
            $table->text('file_path')->nullable();
            $table->string('server_uuid', 191)->nullable()->index();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->string('detection_reason', 255)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->timestamp('first_seen')->nullable()->index();
            $table->timestamp('last_seen')->nullable()->index();
            $table->unsignedInteger('seen_count')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('illegal_files');
    }
};
