<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('panel_rum_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('metric', 48)->index();
            $table->double('value')->nullable();
            $table->string('route', 255)->nullable()->index();
            $table->string('rating', 16)->nullable();
            $table->double('delta')->nullable();
            $table->double('ttfb')->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('api_path', 255)->nullable()->index();
            $table->json('meta')->nullable();
            $table->dateTime('occurred_at')->index();
            $table->timestamps();

            $table->index(['metric', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('panel_rum_events');
    }
};
