<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'telegram_id')) {
                $table->string('telegram_id', 64)->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'madeinweb_panel_created_at')) {
                $table->timestamp('madeinweb_panel_created_at')->nullable()->after('updated_at');
            }
        });

        if (!Schema::hasTable('registration_otp_requests')) {
            Schema::create('registration_otp_requests', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('request_token', 64)->unique();
                $table->string('email', 191);
                $table->string('username', 191);
                $table->string('name_first', 191);
                $table->string('name_last', 191)->default('madeinweb');
                $table->string('telegram_id', 64);
                $table->text('password_encrypted');
                $table->string('otp_hash', 255);
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('otp_expires_at');
                $table->timestamps();

                $table->index('email');
                $table->index('username');
                $table->index('telegram_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('registration_otp_requests')) {
            Schema::drop('registration_otp_requests');
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'telegram_id')) {
                $table->dropColumn('telegram_id');
            }
            if (Schema::hasColumn('users', 'madeinweb_panel_created_at')) {
                $table->dropColumn('madeinweb_panel_created_at');
            }
        });
    }
};
