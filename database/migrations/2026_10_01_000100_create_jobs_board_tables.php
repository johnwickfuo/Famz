<?php

use App\Enums\JobApplicationStatus;
use App\Enums\JobListingStatus;
use App\Enums\JobType;
use App\Enums\PayPeriod;
use App\Enums\RatingParty;
use App\Enums\RatingStatus;
use App\Enums\WorkerAvailability;
use App\Enums\WorkTypeWanted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What somebody can do on a farm, as a tag rather than a sentence.
         *
         * Matching runs on these and nothing else. A worker's own description
         * of themselves is what an employer reads; this is what they are
         * matched by, and keeping the two apart stops the ranking depending on
         * how well somebody writes.
         */
        Schema::create('worker_skills', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();

            // Poultry, livestock, crops, general. Lets the picker group itself
            // without a second table.
            $table->string('sector', 40)->index();

            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });

        /*
         * Somebody looking for farm work.
         *
         * The phone and whatsapp columns on this table are the most sensitive
         * data on the platform. They are never serialised except by
         * WorkerProfile::contactFor(), which takes the viewer and returns
         * nothing at all unless that viewer is an authenticated employer — the
         * omission happens on the server, before the payload is built, and not
         * by hiding a rendered field.
         */
        Schema::create('worker_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('slug', 160)->unique();

            $table->string('full_name');
            $table->string('phone', 32);
            $table->string('whatsapp', 32)->nullable();

            $table->string('state', 64)->index();
            $table->string('lga', 64)->nullable();

            // Changes everything about matching: a willing mover is a candidate
            // for every state, not just their own.
            $table->boolean('willing_to_relocate')->default(false)->index();

            $table->enum('work_type_wanted', WorkTypeWanted::values())
                ->default(WorkTypeWanted::Both->value)
                ->index();

            $table->unsignedTinyInteger('years_experience')->default(0);

            /*
             * Expected pay, in kobo, with the period it is quoted in. Farm
             * labour here is quoted daily far more often than monthly, and a
             * board that only understood salaries would be useless for most of
             * the work on it.
             */
            $table->unsignedBigInteger('expected_pay_min_kobo')->nullable();
            $table->unsignedBigInteger('expected_pay_max_kobo')->nullable();
            $table->enum('pay_period', PayPeriod::values())->default(PayPeriod::Monthly->value);

            $table->enum('availability', WorkerAvailability::values())
                ->default(WorkerAvailability::Immediately->value);

            $table->text('about')->nullable();
            $table->string('photo')->nullable();

            /*
             * Optional, and stored on the private disk. This is not a
             * verification step — the platform verifies nobody — it is
             * something a worker may choose to show an employer.
             */
            $table->string('id_document')->nullable();

            // Two different facts. A worker between jobs is open to work; one
            // who has taken a job for the season is not, but their profile
            // still exists and their ratings still stand.
            $table->boolean('is_open_to_work')->default(true)->index();
            $table->boolean('is_active')->default(true)->index();

            // Denormalised from approved ratings only, recalculated on
            // moderation. Reading it is what every listing page does.
            $table->decimal('rating_average', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'is_open_to_work', 'state']);
        });

        Schema::create('worker_profile_skill', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_skill_id')->constrained()->cascadeOnDelete();

            $table->unique(['worker_profile_id', 'worker_skill_id'], 'worker_profile_skill_unique');
        });

        /*
         * Somebody with work to offer.
         *
         * The company is not the employer and does not check any of this. The
         * profile exists so a worker can see who is asking them to turn up
         * somewhere, which is the only protection this board offers.
         */
        Schema::create('employer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('slug', 160)->unique();

            $table->string('business_name');
            $table->string('business_type', 120)->nullable();

            $table->string('state', 64)->index();
            $table->string('lga', 64)->nullable();
            $table->text('address')->nullable();

            $table->text('about')->nullable();
            $table->string('logo')->nullable();

            $table->string('contact_person')->nullable();
            $table->string('phone', 32);
            $table->string('email')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->decimal('rating_average', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);

            $table->timestamps();
        });

        /*
         * A job.
         *
         * No money passes through the platform for any of this, and nothing on
         * this table implies otherwise: pay is what the employer says they will
         * pay, quoted so a worker can decide whether the journey is worth it.
         */
        Schema::create('job_listings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('employer_profile_id')->constrained()->cascadeOnDelete();

            $table->string('slug', 200)->unique();
            $table->string('title');
            $table->longText('description');

            $table->enum('job_type', JobType::values())->index();

            $table->unsignedInteger('positions_available')->default(1);

            $table->string('state', 64)->index();
            $table->string('lga', 64)->nullable();

            /*
             * The two questions that decide whether a job in another state is
             * possible at all for somebody with nothing. Asking them up front
             * is worth more than any amount of description.
             */
            $table->boolean('is_accommodation_provided')->default(false);
            $table->boolean('is_food_provided')->default(false);

            $table->unsignedBigInteger('pay_min_kobo')->nullable();
            $table->unsignedBigInteger('pay_max_kobo')->nullable();
            $table->enum('pay_period', PayPeriod::values())->default(PayPeriod::Monthly->value);

            $table->date('start_date')->nullable();
            $table->date('application_deadline')->nullable()->index();

            $table->enum('status', JobListingStatus::values())
                ->default(JobListingStatus::Draft->value)
                ->index();

            $table->unsignedInteger('views_count')->default(0);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            // The shape the public board is read in.
            $table->index(['status', 'state', 'job_type']);
            $table->index(['status', 'published_at']);
        });

        Schema::create('job_listing_skill', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_skill_id')->constrained()->cascadeOnDelete();

            $table->unique(['job_listing_id', 'worker_skill_id'], 'job_listing_skill_unique');
        });

        /*
         * Somebody putting themselves forward.
         *
         * One per worker per listing, enforced by a unique index rather than by
         * a check in a controller: a worker who applies twice by double-tapping
         * on a bad connection should not become two applicants.
         */
        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_profile_id')->constrained()->cascadeOnDelete();

            $table->text('cover_message')->nullable();

            $table->enum('status', JobApplicationStatus::values())
                ->default(JobApplicationStatus::Applied->value)
                ->index();

            $table->timestamp('applied_at');
            $table->timestamp('status_changed_at')->nullable();

            // Who moved it, so a disputed "I never rejected them" has an answer.
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['job_listing_id', 'worker_profile_id'], 'job_application_unique');
            $table->index(['worker_profile_id', 'applied_at']);
        });

        /*
         * What each side said about the other afterwards.
         *
         * Gated on a hire, enforced in a policy. One rating per party per
         * application, so neither side can pile on.
         */
        Schema::create('job_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();

            // Which side wrote it, stored rather than inferred from roles: an
            // employer here can be a worker somewhere else.
            $table->enum('rated_by', RatingParty::values());

            // The account that actually typed it, for moderation.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();

            $table->enum('status', RatingStatus::values())
                ->default(RatingStatus::Pending->value)
                ->index();

            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_note')->nullable();

            $table->timestamps();

            $table->unique(['job_application_id', 'rated_by'], 'job_rating_unique');
        });

        /*
         * Who looked at whose contact details, and when.
         *
         * This table is the reason the phone number rule is enforceable rather
         * than merely stated. A recruiter quietly working through every worker
         * in a state to build a call list looks exactly like normal use from
         * any single request; it only looks like harvesting when the requests
         * are counted together.
         */
        Schema::create('worker_profile_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employer_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Whether the contact details were actually released on this view,
            // as opposed to the page being rendered without them.
            $table->boolean('contact_released')->default(false)->index();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();

            // How the rate limit and the harvesting report are both read.
            $table->index(['user_id', 'created_at']);
            $table->index(['worker_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_profile_views');
        Schema::dropIfExists('job_ratings');
        Schema::dropIfExists('job_applications');
        Schema::dropIfExists('job_listing_skill');
        Schema::dropIfExists('job_listings');
        Schema::dropIfExists('employer_profiles');
        Schema::dropIfExists('worker_profile_skill');
        Schema::dropIfExists('worker_profiles');
        Schema::dropIfExists('worker_skills');
    }
};
