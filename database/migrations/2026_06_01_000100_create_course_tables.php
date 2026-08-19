<?php

use App\Enums\CourseLevel;
use App\Enums\CourseStatus;
use App\Enums\LessonType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The academy's own category tree.
         *
         * Deliberately separate from the marketplace's: "Poultry" as a thing to
         * buy and "Poultry" as a thing to learn are not the same shelf, and
         * sharing one tree would mean every new course category appearing in
         * the shop's navigation.
         */
        Schema::create('course_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()
                ->constrained('course_categories')->nullOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
        });

        /*
         * A course. Authored by the company and nobody else — there is no
         * instructor relation here on purpose, and adding one later would be a
         * migration rather than a hole somebody could climb through today.
         */
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_category_id')->constrained()->restrictOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->string('summary', 400);
            $table->longText('description');

            $table->string('cover_image')->nullable();

            // A public trailer, hosted wherever the company likes. Not the
            // lesson videos, which never leave storage.
            $table->string('promo_video_url')->nullable();

            $table->unsignedBigInteger('price_kobo')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->boolean('is_free')->default(false);

            $table->enum('level', CourseLevel::values())->default(CourseLevel::Beginner->value);

            // Minutes, summed from the lessons when the author has not said.
            $table->unsignedInteger('estimated_minutes')->nullable();

            $table->json('what_you_will_learn')->nullable();
            $table->json('requirements')->nullable();

            $table->enum('status', CourseStatus::values())
                ->default(CourseStatus::Draft->value)
                ->index();

            $table->timestamp('published_at')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'course_category_id']);
        });

        Schema::create('course_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('summary')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['course_id', 'sort_order']);
        });

        Schema::create('course_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_module_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->enum('type', LessonType::values())->default(LessonType::Text->value);

            // Text lessons only. Everything else lives behind the file server.
            $table->longText('content')->nullable();

            /*
             * A path INSIDE storage/app/course-content, never under public/.
             * Nothing in the application turns this into a URL: the only way to
             * the bytes is a short-lived signed link through the controller,
             * which checks the enrolment first.
             */
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->unsignedInteger('duration_seconds')->nullable();

            // Playable without buying, so the curriculum is not a locked list.
            $table->boolean('is_preview')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['course_module_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_lessons');
        Schema::dropIfExists('course_modules');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('course_categories');
    }
};
