<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Somebody's right to a course. One purchase, for good.
         *
         * The consent columns are the record that "all sales final" was put in
         * front of them and ticked, with the wording as it stood that day —
         * an argument six months later is about what they were shown, not what
         * the page says now.
         */
        Schema::create('enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();

            $table->string('reference', 40)->unique();

            // The marketplace order that paid for it, where money changed
            // hands. Null for a free course or an administrator's grant.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_reference', 40)->nullable();

            $table->unsignedBigInteger('price_paid_kobo')->default(0);
            $table->string('currency', 3)->default('NGN');

            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('certificate_issued_at')->nullable();

            // Where they were when they last closed the tab.
            $table->foreignId('last_lesson_id')->nullable()
                ->constrained('course_lessons')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();

            $table->boolean('terms_accepted')->default(false);
            $table->timestamp('terms_accepted_at')->nullable();
            $table->text('terms_accepted_text')->nullable();
            $table->string('terms_accepted_ip', 45)->nullable();

            $table->timestamps();

            // One enrolment per person per course. Paying twice for the same
            // course is a refund conversation nobody wants to have.
            $table->unique(['user_id', 'course_id']);
            $table->index(['course_id', 'enrolled_at']);
        });

        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrolment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_lesson_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();

            // Seconds into a video, or a scroll offset in a handout. Whatever
            // it means, it is the player's business, not this table's.
            $table->unsignedInteger('last_position')->default(0);

            $table->timestamps();

            $table->unique(['enrolment_id', 'course_lesson_id'], 'lesson_progress_unique');
            $table->index(['enrolment_id', 'is_completed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
        Schema::dropIfExists('enrolments');
    }
};
