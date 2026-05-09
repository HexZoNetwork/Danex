<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('node_auto_config_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('node_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 24)->index();
            $table->string('target_host', 191);
            $table->unsignedInteger('target_port')->default(22);
            $table->string('target_username', 64)->default('root');
            $table->string('host_fingerprint', 191)->nullable();
            $table->string('bootstrap_auth_type', 32)->default('password_ephemeral_key');
            $table->longText('encrypted_password')->nullable();
            $table->longText('encrypted_private_key')->nullable();
            $table->unsignedInteger('wings_port')->default(8080);
            $table->string('fallback_port_range', 191)->default('8081-8099');
            $table->string('host_key_policy', 32)->default('strict_tofu');
            $table->unsignedInteger('max_ssh_timeout_sec')->default(30);
            $table->string('correlation_id', 64)->index();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('requested_payload')->nullable();
            $table->timestamps();

            $table->index(['node_id', 'status']);
            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_auto_config_runs');
    }
};
