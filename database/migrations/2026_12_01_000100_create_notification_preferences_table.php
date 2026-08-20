<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What somebody has chosen to be told about.
         *
         * Rows are written only when a preference is CHANGED. An empty table
         * means everybody is on the defaults, which is the correct starting
         * state and costs nothing to store — and it means adding a new category
         * later does not require backfilling a row for every user who has ever
         * registered.
         *
         * Two independent switches rather than one. "Show it in the app but do
         * not email me" is the single most common thing people actually want,
         * and a combined setting cannot express it.
         */
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);

            $table->boolean('database')->default(true);
            $table->boolean('email')->default(true);

            $table->timestamps();

            // One row per person per category, enforced here rather than hoped
            // for in a service: a duplicate would make the effective preference
            // depend on row order.
            $table->unique(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
