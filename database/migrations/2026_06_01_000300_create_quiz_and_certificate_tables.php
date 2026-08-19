<?php

use App\Enums\QuizQuestionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->unsignedTinyInteger('pass_mark_percent')->default(70);

            // Null means as many tries as they like. A cap is there to stop
            // somebody clicking through every combination, not to punish.
            $table->unsignedTinyInteger('max_attempts')->nullable();

            $table->boolean('is_required_for_certificate')->default(true);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One final quiz per course. More than one would make "the quiz is
            // passed" an ambiguous statement, and the certificate rule depends
            // on it not being.
            $table->unique('course_id');
        });

        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();

            $table->text('question');
            $table->enum('type', QuizQuestionType::values())
                ->default(QuizQuestionType::SingleChoice->value);

            // Shown after the attempt, right or wrong: a quiz nobody learns
            // from is a gate rather than a lesson.
            $table->text('explanation')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['quiz_id', 'sort_order']);
        });

        Schema::create('quiz_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_question_id')->constrained()->cascadeOnDelete();

            $table->text('text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['quiz_question_id', 'sort_order']);
        });

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrolment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('score_percent')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('question_count')->default(0);
            $table->boolean('passed')->default(false);

            // What they actually chose, so a disputed result can be looked at
            // rather than argued about.
            $table->json('answers')->nullable();

            $table->timestamp('attempted_at')->nullable();

            $table->timestamps();

            $table->index(['enrolment_id', 'passed']);
        });

        /*
         * An issued certificate.
         *
         * The company's identity is COPIED here at issue time, not looked up
         * when the PDF is rendered. A student who downloads their certificate
         * again in two years must get the document they were given, not one
         * bearing whatever the company has since renamed itself — and the
         * verification page must be able to say what it said the day it was
         * issued.
         */
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrolment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();

            // What /verify/{code} resolves. Long enough not to be guessable,
            // short enough to be read off a printed page over the phone.
            $table->string('verification_code', 24)->unique();

            $table->string('holder_name');
            $table->string('course_title');

            $table->string('issuer_name');
            $table->string('issuer_short_name')->nullable();
            $table->string('issuer_rc_number')->nullable();
            $table->string('issuer_logo_path')->nullable();
            $table->string('issuer_signature_name')->nullable();
            $table->string('issuer_signature_title')->nullable();

            $table->unsignedTinyInteger('quiz_score_percent')->nullable();

            $table->timestamp('issued_at');
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('quiz_options');
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quizzes');
    }
};
