<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'avatar_url')) {
                $table->string('avatar_url', 2048)->nullable()->after('gravatar');
            }

            if (!Schema::hasColumn('users', 'birthday')) {
                $table->date('birthday')->nullable()->after('avatar_url');
            }
        });

        DB::table('users')
            ->select(['id', 'created_at'])
            ->whereNull('birthday')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    $createdAt = $user->created_at ? Carbon::parse((string) $user->created_at) : null;
                    if ($createdAt === null) {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['birthday' => $createdAt->toDateString()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'birthday')) {
                $table->dropColumn('birthday');
            }

            if (Schema::hasColumn('users', 'avatar_url')) {
                $table->dropColumn('avatar_url');
            }
        });
    }
};
