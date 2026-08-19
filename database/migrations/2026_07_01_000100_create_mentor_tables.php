<?php

use App\Enums\BillingInterval;
use App\Enums\BillingType;
use App\Enums\ContactMethod;
use App\Enums\MentorStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The only door into being a mentor.
         *
         * There is no public signup link, and this table is the reason: the
         * registration route will not open without a row here that is unused
         * and unexpired. Mentors are people the company has decided to invite,
         * which is a different business from a marketplace anybody may join.
         */
        Schema::create('mentor_invitations', function (Blueprint $table) {
            $table->id();

            // Long and random. It is the whole credential, so it is stored
            // hashed-length rather than as anything guessable, and the URL that
            // carries it is signed on top.
            $table->string('token', 64)->unique();

            // Optional: an invitation may be addressed to somebody in
            // particular, or be a blank one an administrator hands out at a
            // trade fair. When it is set, registration must match it.
            $table->string('email')->nullable()->index();
            $table->string('name')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        /*
         * What matching actually runs on.
         *
         * Kept as its own table rather than as free text on the profile because
         * a tag is something two people can be compared by and a paragraph is
         * not. The paragraph is still captured — it is what a client reads —
         * but it never decides a shortlist.
         */
        Schema::create('specialisations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // Poultry, livestock, crops… so the client's category filter has
            // something to filter on.
            $table->string('sector', 64)->index();

            $table->text('description')->nullable();

            /*
             * Words a client is likely to type for this tag. The fallback
             * matcher reads these when the AI call fails, which is what keeps a
             * shortlist possible with no external service at all.
             */
            $table->json('keywords')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['sector', 'sort_order']);
        });

        Schema::create('mentor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('slug')->unique();

            $table->string('headline');
            $table->text('bio');

            // The free-text answer to "what are you good at". Read by people,
            // and by the AI layer when it maps a client's need onto tags —
            // never the thing a shortlist is ranked on by itself.
            $table->text('strengths');

            $table->unsignedSmallInteger('years_experience')->default(0);
            $table->text('qualifications')->nullable();
            $table->string('affiliation')->nullable();

            $table->enum('preferred_contact_method', ContactMethod::values())
                ->default(ContactMethod::Whatsapp->value);

            /*
             * The number, address or link itself. Never serialised into a
             * response before an engagement is paid for — see
             * MentorProfile::publicCard() and the tests that assert it.
             */
            $table->string('contact_value');

            // Nigerian states this mentor will travel to. Empty means remote
            // only, which `accepts_remote` then has to be true for.
            $table->json('states_served')->nullable();
            $table->boolean('accepts_remote')->default(true);
            $table->boolean('accepts_in_person')->default(false);

            $table->string('avatar')->nullable();

            $table->enum('status', MentorStatus::values())
                ->default(MentorStatus::Pending->value)
                ->index();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('status_note')->nullable();

            /*
             * Derived from approved reviews and completed engagements, and
             * recalculated from them rather than incremented. A counter that
             * drifts from the rows it counts is worse than no counter.
             */
            $table->decimal('average_rating', 3, 2)->nullable();
            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedInteger('engagements_completed')->default(0);

            $table->timestamps();

            $table->index(['status', 'average_rating']);
        });

        Schema::create('mentor_profile_specialisation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mentor_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('specialisation_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['mentor_profile_id', 'specialisation_id'], 'mentor_specialisation_unique');
        });

        Schema::create('mentorship_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mentor_profile_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description');

            $table->enum('billing_type', BillingType::values())->default(BillingType::OneTime->value);

            $table->unsignedBigInteger('price_kobo');
            $table->string('currency', 3)->default('NGN');

            // Only meaningful on a periodic package; null on a one-off.
            $table->enum('billing_interval', BillingInterval::values())->nullable();

            $table->string('duration_description')->nullable();
            $table->unsignedSmallInteger('sessions_included')->nullable();

            // What the client actually gets, as a list rather than a paragraph.
            $table->json('deliverables')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['mentor_profile_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentorship_packages');
        Schema::dropIfExists('mentor_profile_specialisation');
        Schema::dropIfExists('mentor_profiles');
        Schema::dropIfExists('specialisations');
        Schema::dropIfExists('mentor_invitations');
    }
};
