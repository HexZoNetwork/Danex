<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('protect_cleanup_runs')) {
            return;
        }

        Schema::table('protect_cleanup_runs', function (Blueprint $table) {
            if (!Schema::hasColumn('protect_cleanup_runs', 'dry_run')) {
                $table->boolean('dry_run')->default(true)->after('status');
            }
            if (!Schema::hasColumn('protect_cleanup_runs', 'allow_user_deletion')) {
                $table->boolean('allow_user_deletion')->default(false)->after('dry_run');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('protect_cleanup_runs')) {
            return;
        }

        Schema::table('protect_cleanup_runs', function (Blueprint $table) {
            if (Schema::hasColumn('protect_cleanup_runs', 'allow_user_deletion')) {
                $table->dropColumn('allow_user_deletion');
            }
            if (Schema::hasColumn('protect_cleanup_runs', 'dry_run')) {
                $table->dropColumn('dry_run');
            }
        });
    }
};
