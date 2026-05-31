<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('protect_cleanup_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('status', 24)->index();
            $table->unsignedInteger('checked_servers')->default(0);
            $table->unsignedInteger('deleted_servers')->default(0);
            $table->unsignedInteger('skipped_online')->default(0);
            $table->unsignedInteger('skipped_unverified')->default(0);
            $table->unsignedInteger('deleted_users')->default(0);
            $table->unsignedInteger('skipped_admins')->default(0);
            $table->unsignedInteger('skipped_users')->default(0);
            $table->unsignedInteger('reset_admin_markers')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('messages')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protect_cleanup_runs');
    }
};
